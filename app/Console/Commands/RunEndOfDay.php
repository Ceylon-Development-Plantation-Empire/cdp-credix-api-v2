<?php

namespace App\Console\Commands;

use App\Services\Loans\EndOfDayService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunEndOfDay extends Command
{
    protected $signature = 'eod:run {--date= : Business date (Y-m-d). Defaults to today, Asia/Colombo.}';

    protected $description = 'Mark installments due/overdue, raise late charges and rebuild arrears, DPD and risk stage';

    public function handle(EndOfDayService $eod): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('date'))->startOfDay()
            : CarbonImmutable::today();

        if ($date->isFuture()) {
            $this->error('The business date cannot be in the future.');

            return self::FAILURE;
        }

        $result = $eod->run($date);

        $this->info("EOD {$date->toDateString()}: {$result['loans']} loans, {$result['penalties']} late charges, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
