<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\LedgerReconciliationService;
use App\Traits\ActivityLogTrait;
use Illuminate\Console\Command;

/**
 * Nightly proof that the ledger, the GL and the balance caches agree (see
 * LedgerReconciliationService). Exits with a failure code and writes a critical audit entry
 * when anything is off, so the scheduler / monitoring can alert.
 */
class ReconcileLedger extends Command
{
    use ActivityLogTrait;

    protected $signature = 'ledger:reconcile';

    protected $description = 'Check that journals balance, allocations match installments, caches match schedules and the GL matches the loans';

    /**
     * Run the checks and report each one.
     */
    public function handle(LedgerReconciliationService $reconciliation): int
    {
        $result = $reconciliation->run();

        foreach ($result['checks'] as $name => $check) {
            $check['ok'] ? $this->info("OK    {$name}") : $this->error("FAIL  {$name}");

            foreach (array_slice($check['problems'], 0, 20) as $problem) {
                $this->line("      {$problem}");
            }
        }

        if (! $result['reconciled']) {
            $this->logActivity('RECONCILIATION_FAILED', 'Ledger', 'Nightly reconciliation found differences', [
                'failed_checks' => array_keys(array_filter($result['checks'], fn (array $c) => ! $c['ok'])),
            ], 'critical');

            return self::FAILURE;
        }

        $this->info('Ledger reconciled.');

        return self::SUCCESS;
    }
}
