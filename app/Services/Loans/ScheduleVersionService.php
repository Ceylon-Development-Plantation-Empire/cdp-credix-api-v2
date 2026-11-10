<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Enums\ApprovalAction;
use App\Enums\InstallmentStatus;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanInstallment;
use Illuminate\Support\Collection;

/**
 * The revisions of a loan's repayment schedule. Version 1 is the schedule made at disbursement;
 * every restructure or gold renewal adds a version, and what was still unpaid on the old one is
 * marked rescheduled and moves to the new one. Nothing here changes anything: it reads the
 * installments, the loan's timeline and the restructure approvals.
 */
class ScheduleVersionService
{
    /**
     * @return Collection<int, array<string, mixed>> oldest version first
     */
    public function versions(Loan $loan): Collection
    {
        $installments = $loan->installments()->get()->groupBy('schedule_version');
        $latest = (int) $installments->keys()->max();

        // The timeline entries that created a version (they carry schedule_version in their metadata).
        $created = LoanActivity::where('loan_id', $loan->id)->with('performer')->orderBy('id')->get()
            ->filter(fn (LoanActivity $activity) => isset($activity->metadata['schedule_version']))
            ->keyBy(fn (LoanActivity $activity) => (int) $activity->metadata['schedule_version']);

        return $installments->map(function (Collection $lines, int|string $version) use ($latest, $created, $loan) {
            $version = (int) $version;
            $activity = $created->get($version);
            $metadata = $activity?->metadata ?? [];

            return [
                'version' => $version,
                'is_current' => $version === $latest,
                'source' => $version === 1 ? 'disbursement' : (isset($metadata['transaction']) ? 'renewal' : 'restructure'),
                'created_at' => ($activity?->performed_at ?? $lines->min('created_at'))?->toApiString(),
                'created_by' => $activity?->performer ? ['name' => $activity->performer->name] : null,
                'reason' => $activity?->remarks,
                'terms' => $version === 1
                    ? ['tenure' => $lines->count(), 'rate' => null]
                    : ['tenure' => $metadata['tenure'] ?? $lines->count(), 'rate' => isset($metadata['rate']) ? number_format((float) $metadata['rate'], 4, '.', '') : null],
                'installments' => $lines->count(),
                'first_due_date' => $lines->min('due_date')?->toDateString(),
                'last_due_date' => $lines->max('due_date')?->toDateString(),
                'totals' => [
                    'principal_due' => $this->sum($lines, 'principal_due'),
                    'profit_due' => $this->sum($lines, 'profit_due'),
                    'charges_due' => $this->sum($lines, 'charges_due'),
                    'penalty_due' => $this->sum($lines, 'penalty_due'),
                    'paid' => $this->sum($lines, 'total_paid'),
                    'balance' => $this->sum($lines->reject(fn (LoanInstallment $line) => $line->status === InstallmentStatus::Rescheduled), 'balance'),
                ],
                'status_counts' => $lines->groupBy(fn (LoanInstallment $line) => $line->status->value)->map->count(),
            ];
        })->values();
    }

    /**
     * Every restructure request on the loan, newest first, whatever its outcome.
     */
    public function restructureRequests(Loan $loan)
    {
        return $loan->approvalRequests()->where('action', ApprovalAction::Restructure->value)
            ->with(['subject', 'requester', 'decider'])->latest('id')->get();
    }

    /**
     * @param  Collection<int, LoanInstallment>  $lines
     */
    protected function sum(Collection $lines, string $column): string
    {
        return (string) \App\Finance\Support\Money::sum($lines->map(fn (LoanInstallment $line) => (string) $line->{$column}));
    }
}
