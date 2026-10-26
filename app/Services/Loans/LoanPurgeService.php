<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanent deletion of an application that was already deleted (the trash), with everything that belongs to it: guarantors and
 * other parties, installments, payments and their allocations, collateral, documents (and their files), timeline, recovery cases,
 * legal documents, approval requests and OTP codes. Customers on the loan are deleted too, but only when nothing else points at them.
 *
 * The accounting journals are never deleted: the soft delete already mirrored them to zero, so they stay as a balanced pair and only
 * lose the link to the loan. Before anything is removed one audit entry carries a full copy of what is about to go.
 *
 * Rows are removed with the query builder on purpose: the models refuse deletion (immutable ledger), and this is the one place that
 * is allowed to, by its own permission and a typed confirmation.
 */
class LoanPurgeService
{
    use ActivityLogTrait;

    /**
     * @return array{loans: int, deleted: array<string, int>, customers_deleted: list<string>, customers_kept: list<array{customer_no: string, reason: string}>}
     */
    public function purge(Loan $loan, string $reason, string $confirmApplicationNo, User $by): array
    {
        if (! $loan->trashed()) {
            throw new BusinessRuleException('LOAN_NOT_IN_TRASH', 'Delete the application first; only a deleted application can be deleted permanently.', 409);
        }

        if ($loan->isMemberLoan()) {
            throw new BusinessRuleException('MEMBER_LOAN_ACTION', 'Delete the group facility permanently; its member loans go with it.', 422);
        }

        if ($confirmApplicationNo !== $loan->application_no) {
            throw new BusinessRuleException('PURGE_CONFIRMATION_REQUIRED', 'Type the application number exactly as shown to delete it permanently.', 422, ['confirm_text' => $loan->application_no]);
        }

        $files = [];

        $report = DB::transaction(function () use ($loan, $reason, $by, &$files) {
            $ids = DB::table('loans')->where('id', $loan->id)->orWhere('parent_id', $loan->id)->pluck('id')->all();

            $this->assertBooksAreZero($ids);

            $transactionIds = DB::table('loan_transactions')->whereIn('loan_id', $ids)->pluck('id')->all();
            $collateralIds = DB::table('collaterals')->whereIn('loan_id', $ids)->pluck('id')->all();
            $partyIds = DB::table('loan_parties')->whereIn('loan_id', $ids)->pluck('id')->all();

            $customerIds = collect()
                ->merge(DB::table('loan_parties')->whereIn('loan_id', $ids)->pluck('customer_id'))
                ->merge(DB::table('loans')->whereIn('id', $ids)->pluck('customer_id'))
                ->merge(DB::table('collaterals')->whereIn('loan_id', $ids)->pluck('owner_customer_id'))
                ->filter()->unique()->values()->all();

            $documents = DB::table('documents')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('documentable_type', (new Loan)->getMorphClass())->whereIn('documentable_id', $ids))
                ->orWhere(fn ($w) => $w->where('documentable_type', (new Collateral)->getMorphClass())->whereIn('documentable_id', $collateralIds ?: [0])));

            $this->snapshot($loan, $ids, $transactionIds, $collateralIds, $documents->get(), $reason, $by);

            $deleted = [];
            $files = array_merge($files, $documents->pluck('path')->filter()->all());
            $deleted['documents'] = (clone $documents)->delete();

            $deleted['loan_allocations'] = DB::table('loan_allocations')->whereIn('loan_transaction_id', $transactionIds ?: [0])->delete();

            // The journals stay (mirrored to zero); they only lose the link to rows that are going.
            DB::table('gl_journals')->where(fn ($q) => $q->whereIn('loan_id', $ids)->orWhereIn('loan_transaction_id', $transactionIds ?: [0]))
                ->update(['loan_id' => null, 'loan_transaction_id' => null]);

            DB::table('loan_transactions')->whereIn('id', $transactionIds ?: [0])->update(['reverses_transaction_id' => null]);
            $deleted['loan_transactions'] = DB::table('loan_transactions')->whereIn('id', $transactionIds ?: [0])->delete();
            $deleted['loan_installments'] = DB::table('loan_installments')->whereIn('loan_id', $ids)->delete();
            $deleted['loan_activities'] = DB::table('loan_activities')->whereIn('loan_id', $ids)->delete();
            $deleted['recovery_cases'] = DB::table('recovery_cases')->whereIn('loan_id', $ids)->delete();
            $deleted['legal_documents'] = DB::table('legal_documents')->whereIn('loan_id', $ids)->delete();
            $deleted['collaterals'] = DB::table('collaterals')->whereIn('loan_id', $ids)->delete();

            $deleted['approval_requests'] = DB::table('approval_requests')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('subject_type', (new Loan)->getMorphClass())->whereIn('subject_id', $ids))
                ->orWhere(fn ($w) => $w->where('subject_type', (new LoanTransaction)->getMorphClass())->whereIn('subject_id', $transactionIds ?: [0])))->delete();

            $deleted['otp_challenges'] = DB::table('otp_challenges')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('subject_type', (new Loan)->getMorphClass())->whereIn('subject_id', $ids))
                ->orWhere(fn ($w) => $w->where('subject_type', (new LoanParty)->getMorphClass())->whereIn('subject_id', $partyIds ?: [0])))->delete();

            $deleted['notifications'] = DB::table('notifications')->whereIn('loan_id', $ids)->delete();
            $deleted['loan_parties'] = DB::table('loan_parties')->whereIn('loan_id', $ids)->delete();

            DB::table('loans')->whereIn('id', $ids)->update(['parent_id' => null]);
            $deleted['loans'] = DB::table('loans')->whereIn('id', $ids)->delete();

            [$gone, $kept] = $this->removeUnusedCustomers($customerIds, $files);

            $deleted['customers'] = count($gone);

            $this->logActivity('DELETE', 'Loan', "Permanently deleted application {$loan->application_no}", [
                'reason' => $reason,
                'deleted' => $deleted,
                'customers_deleted' => $gone,
                'customers_kept' => $kept,
            ], 'warning');

            return ['loans' => count($ids), 'deleted' => $deleted, 'customers_deleted' => $gone, 'customers_kept' => $kept];
        }, attempts: 3);

        // The stored files go only after the database commit, so a failed delete never leaves rows pointing at missing files.
        foreach (array_unique($files) as $path) {
            Storage::disk(DocumentService::DISK)->delete($path);
        }

        return $report;
    }

    /**
     * Only a loan whose accounting is already mirrored to zero can go; otherwise the books would lose entries.
     *
     * @param  list<int>  $ids
     */
    protected function assertBooksAreZero(array $ids): void
    {
        $open = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', 'j.id', '=', 'l.gl_journal_id')
            ->whereIn('j.loan_id', $ids)
            ->where('j.status', 'posted')
            ->groupBy('l.gl_account_id')
            ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) > 0.0001')
            ->exists();

        if ($open) {
            throw new BusinessRuleException('LOAN_BOOKS_NOT_ZERO', 'The accounting entries of this loan are not reversed. Restore the application and delete it again so its entries are mirrored first.', 409);
        }
    }

    /**
     * One audit entry with a copy of everything that is about to be removed, so nothing is lost silently.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $transactionIds
     * @param  list<int>  $collateralIds
     */
    protected function snapshot(Loan $loan, array $ids, array $transactionIds, array $collateralIds, $documents, string $reason, User $by): void
    {
        $rows = fn (string $table, string $column, array $values) => DB::table($table)->whereIn($column, $values ?: [0])->get()->map(fn ($row) => (array) $row)->all();

        $this->logActivity('DELETE', 'Loan', "Snapshot before permanently deleting application {$loan->application_no}", [
            'reason' => $reason,
            'deleted_by' => $by->id,
            'loans' => $rows('loans', 'id', $ids),
            'parties' => $rows('loan_parties', 'loan_id', $ids),
            'installments' => $rows('loan_installments', 'loan_id', $ids),
            'transactions' => $rows('loan_transactions', 'id', $transactionIds),
            'allocations' => $rows('loan_allocations', 'loan_transaction_id', $transactionIds),
            'collaterals' => $rows('collaterals', 'id', $collateralIds),
            'documents' => $documents->map(fn ($row) => (array) $row)->all(),
            'timeline' => $rows('loan_activities', 'loan_id', $ids),
            'recovery_cases' => $rows('recovery_cases', 'loan_id', $ids),
            'legal_documents' => $rows('legal_documents', 'loan_id', $ids),
        ], 'warning');
    }

    /**
     * Delete a customer of the loan only when nothing else points at them (another loan, a guarantee, a payment, a group, collateral).
     *
     * @param  list<int>  $customerIds
     * @param  list<string>  $files
     * @return array{0: list<string>, 1: list<array{customer_no: string, reason: string}>}
     */
    protected function removeUnusedCustomers(array $customerIds, array &$files): array
    {
        $gone = [];
        $kept = [];

        foreach ($customerIds as $id) {
            $customer = DB::table('customers')->where('id', $id)->first(['id', 'customer_no']);

            if (! $customer) {
                continue;
            }

            $usedBy = collect([
                'another loan' => DB::table('loans')->where('customer_id', $id)->exists(),
                'a loan as borrower or guarantor' => DB::table('loan_parties')->where('customer_id', $id)->exists(),
                'collateral' => DB::table('collaterals')->where('owner_customer_id', $id)->exists(),
                'a payment' => DB::table('loan_transactions')->where('payer_customer_id', $id)->exists(),
                'a borrower group' => DB::table('borrower_group_members')->where('customer_id', $id)->exists(),
            ])->filter()->keys();

            if ($usedBy->isNotEmpty()) {
                $kept[] = ['customer_no' => $customer->customer_no, 'reason' => 'Still used by '.$usedBy->implode(', ')];

                continue;
            }

            $documents = DB::table('documents')->where('documentable_type', (new Customer)->getMorphClass())->where('documentable_id', $id);
            $files = array_merge($files, $documents->pluck('path')->filter()->all());
            $documents->delete();

            DB::table('otp_challenges')->where('subject_type', (new Customer)->getMorphClass())->where('subject_id', $id)->delete();
            DB::table('customer_bank_accounts')->where('customer_id', $id)->delete();
            DB::table('customer_financials')->where('customer_id', $id)->delete();
            DB::table('customers')->where('id', $id)->delete();

            $gone[] = $customer->customer_no;
        }

        return [$gone, $kept];
    }
}
