<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\SmsService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Test-safe SMS: with SMS_ALLOWED_NUMBERS set, nothing reaches a number outside the list.
 *
 * A message to an allowed number is sent as it is. A message to anyone else is redirected to the
 * allowed handsets, prefixed with the (masked) number it was meant for, so a tester sees exactly
 * what the customer would have received (OTP codes included) and no real borrower is texted while
 * testing against real records.
 *
 * Never used in production (AppServiceProvider): there it would send customers' codes to staff.
 */
class AllowListedSmsService extends SmsService
{
    /** @var list<string> E.164 numbers */
    protected array $allowed;

    /**
     * @param  list<string>  $allowedNumbers
     */
    public function __construct(
        protected SmsService $inner,
        array $allowedNumbers,
    ) {
        $this->allowed = array_values(array_unique(array_filter(array_map(fn ($n) => PhoneNormalizer::normalize($n), $allowedNumbers))));
    }

    public function sendSms($numbers, string $message, int $paymentMethod = 0): bool
    {
        $numbers = is_array($numbers) ? $numbers : [$numbers];
        $direct = [];
        $redirected = [];

        foreach ($numbers as $number) {
            in_array(PhoneNormalizer::normalize($number), $this->allowed, true) ? $direct[] = $number : $redirected[] = $number;
        }

        $ok = true;

        if ($direct !== []) {
            $ok = $this->inner->sendSms($direct, $message, $paymentMethod);
        }

        foreach ($redirected as $number) {
            $masked = PhoneNormalizer::mask($number);
            Log::info('SMS redirected to the allow-list', ['intended_for' => $masked]);
            $ok = $this->inner->sendSms($this->allowed, "[TEST for {$masked}] {$message}", $paymentMethod) && $ok;
        }

        return $ok;
    }

    /**
     * @return list<string>
     */
    public function allowedNumbers(): array
    {
        return $this->allowed;
    }
}
