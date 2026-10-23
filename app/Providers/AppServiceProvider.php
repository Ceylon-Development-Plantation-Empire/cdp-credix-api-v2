<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\LoanStatusChanged;
use App\Events\PaymentPosted;
use App\Events\RecoveryCaseOpened;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\GlJournal;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Sms\AllowListedSmsService;
use App\Services\SmsService;
use App\Support\BusinessDate;
use App\Support\Charges\ChargeTypeRegistry;
use App\Support\Collateral\CollateralTypes;
use App\Support\Lookups\LookupRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Outside production, SMS_ALLOWED_NUMBERS keeps every message (OTP codes included) on the
        // testers' handsets. In production it would send customers' codes to staff: never applied.
        $this->app->extend(SmsService::class, function (SmsService $sms, $app) {
            $allowed = (array) config('services.dialog_sms.allowed_numbers', []);

            if ($allowed === []) {
                return $sms;
            }

            if ($app->environment('production')) {
                Log::warning('SMS_ALLOWED_NUMBERS is set in production and is ignored; SMS go to the real recipients.');

                return $sms;
            }

            return new AllowListedSmsService($sms, $allowed);
        });

        // One instance per request: each code list / the charge-type table is read at most once.
        $this->app->singleton(LookupRegistry::class);
        $this->app->singleton(ChargeTypeRegistry::class);
        $this->app->singleton(CollateralTypes::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SECURITY: one password policy for every password set in the system (Password::defaults()):
        // 12+ characters, mixed case, a number and a symbol; in production also refused when it
        // appears in a known breach (k-anonymity range lookup: only 5 hash characters leave the server).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()
            ->when($this->app->environment('production'), fn (Password $rule) => $rule->uncompromised()));

        $this->configureEloquent();
        $this->configureDates();
        $this->configureRateLimiting();
        $this->registerNotificationListeners();
    }

    /**
     * Times are stored, computed and shown in Sri Lankan time (+05:30): ->toApiString() on any date
     * (see App\Support\BusinessDate).
     */
    protected function configureDates(): void
    {
        $toApiString = function (): string {
            /** @var CarbonInterface $this */
            return (string) BusinessDate::iso($this);
        };

        Carbon::macro('toApiString', $toApiString);
        CarbonImmutable::macro('toApiString', $toApiString);
    }

    /**
     * Polymorphic aliases and strict mode.
     *
     * DECISION: an enforced morph map stores short stable aliases ("loan", "customer" …) in the
     * *_type columns instead of PHP class names, so moving or renaming a class can never orphan
     * documents, approvals, OTP challenges or notifications; an unmapped model fails loudly.
     *
     * Outside production Eloquent is strict: assigning a column that is not fillable, or reading
     * one that was not selected, throws instead of failing silently.
     */
    protected function configureEloquent(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'customer' => Customer::class,
            'loan' => Loan::class,
            'loan_party' => LoanParty::class,
            'loan_transaction' => LoanTransaction::class,
            'collateral' => Collateral::class,
            'product' => Product::class,
            'gl_journal' => GlJournal::class,
        ]);

        $strict = ! $this->app->environment('production');
        Model::preventSilentlyDiscardingAttributes($strict);
        Model::preventAccessingMissingAttributes($strict);
        Model::preventLazyLoading($strict);
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

        // Every API request: 240 a minute per signed-in user (one screen makes several calls),
        // 120 per address for anyone not signed in.
        RateLimiter::for('api', fn (Request $request) => $request->user('api')
            ? Limit::perMinute(240)->by('api-user|'.$request->user('api')->id)
            : Limit::perMinute(120)->by('api-ip|'.$request->ip()));

        // Forgotten password: asking for a code is tight per address and email, and so is guessing one.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('password-reset|'.$request->ip().'|'.mb_strtolower((string) $request->input('email', $request->input('challenge_id')))),
            Limit::perMinute(20)->by('password-reset-ip|'.$request->ip()),
        ]);

        // Own password change: 5 tries a minute per user (guessing the current password is slow).
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('password-change|'.$request->user()?->id));

        RateLimiter::for('otp-send', function (Request $request) {
            return Limit::perMinute(3)->by('otp-send|'.$request->user()?->id.'|'.$request->route('publicId').'|'.$request->route('partyId').'|'.$request->route('customerPublicId'));
        });

        // The public customer review link: no user is signed in, so the buckets are the link and the address.
        // Opening and correcting are generous; asking for a code is tight; guessing a code is tighter still.
        RateLimiter::for('review-open', fn (Request $request) => Limit::perMinute(30)->by('review-open|'.$request->ip().'|'.sha1((string) $request->route('token'))));
        RateLimiter::for('review-otp', fn (Request $request) => Limit::perMinute(3)->by('review-otp|'.sha1((string) $request->route('token'))));
        RateLimiter::for('review-confirm', fn (Request $request) => Limit::perMinute(6)->by('review-confirm|'.sha1((string) $request->route('token'))));

        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinute(10)->by('otp-verify|'.$request->user()?->id.'|'.$request->route('publicId').'|'.$request->route('partyId').'|'.$request->route('customerPublicId'));
        });
    }
}
