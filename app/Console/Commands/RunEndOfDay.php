<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Loans\EndOfDayService;
use App\Support\BusinessDate;
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
            : BusinessDate::todayImmutable();

        // Compare calendar days: the business date is midnight UTC of the Colombo date, which is still
        // "in the future" by the clock for the first hours of a Colombo day.
        if ($date->toDateString() > BusinessDate::todayImmutable()->toDateString()) {
            $this->error('The business date cannot be in the future.');

            return self::FAILURE;
        }

        $result = $eod->run($date);

        $this->info("EOD {$date->toDateString()}: {$result['loans']} loans, {$result['penalties']} late charges, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
