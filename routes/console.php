<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Nightly loan servicing run, after midnight Sri Lanka time.
Schedule::command('eod:run')->dailyAt('00:05')->timezone('Asia/Colombo')->withoutOverlapping()->onOneServer();
Schedule::command('kyc:expire')->dailyAt('00:30')->timezone('Asia/Colombo')->onOneServer();

// Proof that the ledger, the GL and the balance caches agree, after the nightly run. A failure
// exits non-zero and writes a critical audit entry for monitoring to alert on.
Schedule::command('ledger:reconcile')->dailyAt('01:30')->timezone('Asia/Colombo')->withoutOverlapping()->onOneServer();

// Month-end loan loss provision, after the day's payments and before the next nightly run.
Schedule::command('gl:provision')->lastDayOfMonth('23:30')->timezone('Asia/Colombo')->withoutOverlapping()->onOneServer();

// Customer reminders in the morning, never at night.
Schedule::command('notifications:reminders due')->dailyAt('08:00')->timezone('Asia/Colombo')->onOneServer();
Schedule::command('notifications:reminders overdue')->dailyAt('08:30')->timezone('Asia/Colombo')->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
