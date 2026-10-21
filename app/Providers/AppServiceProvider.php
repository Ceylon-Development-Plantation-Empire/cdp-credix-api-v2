<?php

namespace App\Providers;

use App\Events\LoanStatusChanged;
use App\Events\PaymentPosted;
use App\Events\RecoveryCaseOpened;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->registerNotificationListeners();
    }

    /**
     * Customer messages for domain events (one global pipeline, see NotificationService).
     * Registered here explicitly rather than by listener discovery, so each event is handled once.
     */
    protected function registerNotificationListeners(): void
    {
        Event::listen(LoanStatusChanged::class, [CustomerNotifier::class, 'handleStatusChange']);
        Event::listen(PaymentPosted::class, [CustomerNotifier::class, 'handlePayment']);
        Event::listen(RecoveryCaseOpened::class, [CustomerNotifier::class, 'handleRecoveryOpened']);
    }

    /**
     * Named limiters for OTP endpoints.
     *
     * A plain "throttle:3,1" counts every throttled request of the user in one bucket (including the
     * global API limit), so normal use of other screens would block OTPs. Each limiter here has its
     * own bucket per user AND per subject (customer, or loan party), so one person's OTPs cannot be
     * sprayed and an officer working on several people (a group loan's members) is not blocked.
     */
    protected function configureRateLimiting(): void
    {
        // Staff login: 5 tries a minute per username from one address, 30 per address in total
        // (an office behind one router). Its own bucket, so ordinary API calls never lock it.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login|'.$request->ip().'|'.mb_strtolower((string) $request->input('login'))),
            Limit::perMinute(30)->by('login-ip|'.$request->ip()),
        ]);

        RateLimiter::for('otp-send', function (Request $request) {
            return Limit::perMinute(3)->by('otp-send|'.$request->user()?->id.'|'.$request->route('publicId').'|'.$request->route('partyId'));
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinute(10)->by('otp-verify|'.$request->user()?->id.'|'.$request->route('publicId').'|'.$request->route('partyId'));
        });
    }
}
