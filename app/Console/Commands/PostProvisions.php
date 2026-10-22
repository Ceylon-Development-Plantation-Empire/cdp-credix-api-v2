<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\ProvisionService;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Month-end provision for loan losses. Safe to run again for the same date.
 */
class PostProvisions extends Command
{
    protected $signature = 'gl:provision {--date= : reporting date (Y-m-d), today by default}';

    protected $description = 'Post the loan loss provision top-ups and releases for a reporting date';

    public function handle(ProvisionService $provisions): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? BusinessDate::today()->toDateString());

        // Compare calendar days, not instants (see RunEndOfDay).
        if ($date->toDateString() > BusinessDate::todayImmutable()->toDateString()) {
            $this->error('The reporting date cannot be in the future.');

            return self::FAILURE;
        }

        $result = $provisions->run($date);

        $this->info("Provisioning {$date->toDateString()}: {$result['loans']} loan(s), {$result['journals']} journal(s), required {$result['total_required']}, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
