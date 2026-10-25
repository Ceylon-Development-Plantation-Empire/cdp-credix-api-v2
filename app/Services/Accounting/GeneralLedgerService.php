<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\ApprovalAction;
use App\Enums\GlJournalSource;
use App\Enums\GlJournalStatus;
use App\Enums\SettingType;
use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\ApprovalRequest;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlJournalLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\BusinessDate;
use App\Traits\ActivityLogTrait;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of the general ledger.
 *
 * Every journal is checked before anything is saved: at least two lines, no negative amounts,
 * debits equal to credits, active accounts only. A journal dated inside a closed period is
 * entered on the first open day instead (its value date keeps the real date).
 */
class GeneralLedgerService
{
    use ActivityLogTrait;

    public const CLOSED_THROUGH_SETTING = 'gl.closed_through';

    /**
     * Accounts that summarise the loan sub-ledger. A manual entry on them would make the GL
     * disagree with the loans, so only the posting rules write to them.
     */
    public const CONTROL_ACCOUNTS = [
        'loans-receivable',
        'islamic-financing-receivable',
        'interest-receivable',
        'profit-receivable',
        'charges-receivable',
        'penalty-receivable',
        'interest-suspense',
        'profit-suspense',
        'loan-loss-provision',
    ];

    /** @var array<string, GlAccount> */
    protected array $accounts = [];

    public function __construct(
        protected ApprovalRequestService $approvals,
    ) {}

    /**
     * A system account by slug (the posting rules never use ids or codes).
     */
    public function account(string $slug): GlAccount
    {
        return $this->accounts[$slug] ??= GlAccount::where('slug', $slug)->first()
            ?? throw new LogicException("The system GL account '{$slug}' is missing.");
    }

    /**
     * @param  list<array{account: GlAccount, debit: BigDecimal, credit: BigDecimal, memo?: string|null}>  $lines
     * @param  array<string, mixed>  $attributes  source_key, branch_id, loan_id, loan_transaction_id, created_by
     */
    public function write(GlJournalSource $source, CarbonInterface $valueDate, string $narration, array $lines, array $attributes = [], GlJournalStatus $status = GlJournalStatus::Posted): GlJournal
    {
        $total = $this->assertBalanced($lines);

        $journal = (new GlJournal)->forceFill($attributes + [
            'source' => $source,
            'entry_date' => $this->entryDate($valueDate)->toDateString(),
            'value_date' => $valueDate->toDateString(),
            'narration' => mb_substr($narration, 0, 255),
            'total' => (string) $total,
            'status' => $status,
            'posted_at' => $status === GlJournalStatus::Posted ? now() : null,
        ]);
        $journal->save();

        foreach ($lines as $line) {
            GlJournalLine::create([
                'gl_journal_id' => $journal->id,
                'gl_account_id' => $line['account']->id,
                'debit' => (string) $line['debit'],
                'credit' => (string) $line['credit'],
                'memo' => $line['memo'] ?? null,
            ]);
        }

        return $journal;
    }

    /**
     * Net signed amounts per account (positive = debit) into journal lines. Accounts that net to
     * zero are left out, so a waiver of profit that was never recognised produces no lines at all.
     *
     * @param  array<string, BigDecimal>  $entries  slug => signed amount
     * @return list<array{account: GlAccount, debit: BigDecimal, credit: BigDecimal}>
     */
    public function linesFrom(array $entries): array
    {
        $lines = [];

        foreach ($entries as $slug => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            $lines[] = [
                'account' => $this->account($slug),
                'debit' => $amount->isPositive() ? $amount : Money::zero(),
                'credit' => $amount->isNegative() ? $amount->negated() : Money::zero(),
            ];
        }

        return $lines;
    }

    /**
     * Maker: a manual journal is saved as pending and posted only when a second person approves it.
     *
     * @param  array{value_date: string, narration: string, branch_id?: int|null, lines: list<array{gl_account_id: int, debit?: string|null, credit?: string|null, memo?: string|null}>}  $data
     */
    public function requestManual(array $data, User $by): ApprovalRequest
    {
        $valueDate = CarbonImmutable::parse($data['value_date']);
        $this->assertOpen($valueDate);

        $accounts = GlAccount::whereIn('id', array_column($data['lines'], 'gl_account_id'))->get()->keyBy('id');
        $control = $accounts->filter(fn (GlAccount $a) => in_array($a->slug, self::CONTROL_ACCOUNTS, true));

        if ($control->isNotEmpty()) {
            throw new BusinessRuleException('CONTROL_ACCOUNT', 'Loan control accounts change only through loan transactions, never by a manual journal.', 422, [
                'accounts' => $control->pluck('code')->values()->all(),
            ]);
        }

        $lines = array_map(fn (array $l) => [
            'account' => $accounts[$l['gl_account_id']],
            'debit' => Money::of($l['debit'] ?? '0'),
            'credit' => Money::of($l['credit'] ?? '0'),
            'memo' => $l['memo'] ?? null,
        ], $data['lines']);

        return DB::transaction(function () use ($data, $valueDate, $lines, $by) {
            $journal = $this->write(GlJournalSource::Manual, $valueDate, $data['narration'], $lines, [
                'branch_id' => $data['branch_id'] ?? null,
                'created_by' => $by->id,
            ], GlJournalStatus::Pending);

            return $this->approvals->create($journal, ApprovalAction::ManualJournal, [
                'journal_no' => $journal->journal_no,
                'total' => (string) $journal->total,
            ], $data['narration'], $by);
        }, attempts: 3);
    }

    /**
     * Replace your own pending manual journal: the old request is withdrawn and the corrected journal is
     * sent for approval as a new one (a journal waiting for approval is never edited in place).
     *
     * @param  array<string, mixed>  $data  Validated ManualJournalRequest data.
     */
    public function updateManual(GlJournal $journal, array $data, User $by): ApprovalRequest
    {
        return DB::transaction(function () use ($journal, $data, $by) {
            $this->approvals->cancel($this->ownPendingRequest($journal, $by), $by);

            return $this->requestManual($data, $by);
        }, attempts: 3);
    }

    /**
     * "Delete" a journal without erasing history:
     * - pending: withdrawn by its maker (the journal stays, as rejected);
     * - posted manual journal: a reversing journal (debits and credits swapped) is sent for approval.
     * Journals the loan postings wrote are corrected through the loan transaction, never here.
     *
     * @return GlJournal|ApprovalRequest the withdrawn journal, or the approval request of the reversal
     */
    public function deleteManual(GlJournal $journal, string $reason, User $by): GlJournal|ApprovalRequest
    {
        if ($journal->status === GlJournalStatus::Pending) {
            $this->approvals->cancel($this->ownPendingRequest($journal, $by), $by);

            return $journal->refresh();
        }

        if ($journal->source !== GlJournalSource::Manual || $journal->status !== GlJournalStatus::Posted) {
            throw new BusinessRuleException('JOURNAL_NOT_DELETABLE', 'Only a manual journal can be reversed here; loan postings are corrected through the loan transaction.', 409);
        }

        $label = "Reversal of {$journal->journal_no}";

        if (GlJournal::where('narration', 'like', $label.'%')->whereIn('status', [GlJournalStatus::Pending->value, GlJournalStatus::Posted->value])->exists()) {
            throw new BusinessRuleException('JOURNAL_ALREADY_REVERSED', 'This journal already has a reversal pending or posted.', 409);
        }

        return $this->requestManual([
            'value_date' => BusinessDate::today()->toDateString(),
            'narration' => mb_substr("{$label}: {$reason}", 0, 255),
            'branch_id' => $journal->branch_id,
            'lines' => $journal->lines()->get()->map(fn (GlJournalLine $line) => [
                'gl_account_id' => $line->gl_account_id,
                'debit' => (string) $line->credit,
                'credit' => (string) $line->debit,
                'memo' => $label,
            ])->all(),
        ], $by);
    }

    protected function ownPendingRequest(GlJournal $journal, User $by): ApprovalRequest
    {
        if ($journal->status !== GlJournalStatus::Pending || $journal->source !== GlJournalSource::Manual) {
            throw new BusinessRuleException('JOURNAL_NOT_PENDING', 'Only a manual journal that is still waiting for approval can be changed.', 409);
        }

        if ($journal->created_by !== $by->id) {
            throw new BusinessRuleException('NOT_REQUESTER', 'Only the person who entered the journal can change or withdraw it.', 403);
        }

        return ApprovalRequest::withoutGlobalScope('branch')
            ->where('subject_type', $journal->getMorphClass())
            ->where('subject_id', $journal->id)
            ->where('status', 'pending')
            ->firstOrFail();
    }

    /**
     * Checker (called by ManualJournalHandler inside the decision transaction).
     */
    public function decideManual(GlJournal $journal, bool $approved, User $approver): GlJournal
    {
        $journal = GlJournal::withoutGlobalScope('branch')->whereKey($journal->id)->lockForUpdate()->firstOrFail();

        if ($journal->status !== GlJournalStatus::Pending) {
            throw new BusinessRuleException('JOURNAL_NOT_PENDING', 'This journal has already been decided.', 409);
        }

        if ($approved) {
            // The period may have been closed while the journal waited.
            $this->assertOpen($journal->value_date);
            $journal->forceFill(['status' => GlJournalStatus::Posted, 'approved_by' => $approver->id, 'posted_at' => now()])->save();
        } else {
            $journal->forceFill(['status' => GlJournalStatus::Rejected, 'approved_by' => $approver->id])->save();
        }

        return $journal;
    }

    public function closedThrough(): ?CarbonImmutable
    {
        $value = Setting::value(self::CLOSED_THROUGH_SETTING);

        return blank($value) ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * Close the books up to and including a date. Nothing can be entered on or before it again;
     * later movements with an older value date are entered on the first open day.
     */
    public function closePeriod(CarbonImmutable $through, User $by): CarbonImmutable
    {
        $current = $this->closedThrough();

        if ($current && $through->lessThanOrEqualTo($current)) {
            throw new BusinessRuleException('PERIOD_ALREADY_CLOSED', "The books are already closed through {$current->toDateString()}.", 422);
        }

        if ($through->greaterThanOrEqualTo(BusinessDate::todayImmutable())) {
            throw new BusinessRuleException('PERIOD_NOT_ENDED', 'Only a past date can be closed.', 422);
        }

        $pending = GlJournal::withoutGlobalScope('branch')
            ->where('status', GlJournalStatus::Pending->value)
            ->where('value_date', '<', $through->addDay()->toDateString())
            ->count();

        if ($pending > 0) {
            throw new BusinessRuleException('PENDING_JOURNALS', 'Decide the manual journals waiting for approval in this period first.', 422, ['pending' => $pending]);
        }

        // Kept in settings but never editable through the settings screen; only this method moves it.
        $setting = Setting::firstOrNew(['key' => self::CLOSED_THROUGH_SETTING]);
        $setting->forceFill([
            'group' => 'gl',
            'type' => SettingType::String,
            'value' => $through->toDateString(),
            'rules' => ['value' => 'prohibited'],
            'label' => 'Books closed through',
            'description' => 'Changed only by closing a period (GL Period Close).',
            'updated_by' => $by->id,
        ])->save();

        $this->logActivity('GL_PERIOD_CLOSE', 'GlJournal', "Closed the books through {$through->toDateString()}", [
            'previous' => $current?->toDateString(),
        ]);

        return $through;
    }

    protected function entryDate(CarbonInterface $valueDate): CarbonImmutable
    {
        $date = CarbonImmutable::parse($valueDate->toDateString());
        $closed = $this->closedThrough();

        return $closed && $date->lessThanOrEqualTo($closed) ? $closed->addDay() : $date;
    }

    protected function assertOpen(CarbonInterface $valueDate): void
    {
        $closed = $this->closedThrough();

        if ($closed && CarbonImmutable::parse($valueDate->toDateString())->lessThanOrEqualTo($closed)) {
            throw new BusinessRuleException('PERIOD_CLOSED', "The books are closed through {$closed->toDateString()}.", 422);
        }
    }

    /**
     * @param  list<array{account: GlAccount, debit: BigDecimal, credit: BigDecimal}>  $lines
     */
    protected function assertBalanced(array $lines): BigDecimal
    {
        if (count($lines) < 2) {
            throw new LogicException('A journal needs at least two lines.');
        }

        foreach ($lines as $line) {
            $debit = $line['debit'];
            $credit = $line['credit'];

            if ($debit->isNegative() || $credit->isNegative() || $debit->isPositive() === $credit->isPositive()) {
                throw new BusinessRuleException('JOURNAL_LINE_INVALID', 'Each line is either a debit or a credit, above zero.', 422);
            }

            if (! $line['account']->is_active) {
                throw new BusinessRuleException('GL_ACCOUNT_INACTIVE', "Account {$line['account']->code} is inactive.", 422);
            }
        }

        $debits = Money::sum(array_column($lines, 'debit'));
        $credits = Money::sum(array_column($lines, 'credit'));

        if (! $debits->isEqualTo($credits)) {
            throw new BusinessRuleException('JOURNAL_NOT_BALANCED', "Debits ({$debits}) and credits ({$credits}) must be equal.", 422);
        }

        return $debits;
    }
}
