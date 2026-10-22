<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time passwords for every purpose.
 *
 * Security rules (each one fixes a weakness of the old system):
 * - the code comes from random_int (a CSPRNG), never rand();
 * - only an HMAC of the code is stored and the code is never returned by the API or logged;
 * - wrong attempts are counted across resends, so resending never gives more guesses;
 * - the attempt is counted inside a row lock, so parallel requests cannot guess in parallel;
 * - a challenge is bound to a payload hash: if the data the customer is confirming changes
 *   after the SMS was sent, the old code no longer confirms anything.
 */
class OtpService
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    /**
     * Create a challenge and send the code by SMS. Any earlier pending challenge for the same
     * subject and purpose is superseded, so only the newest code works.
     *
     * @param  string  $message  SMS text with :code and :minutes placeholders.
     * @param  array<string, mixed>|null  $payload  What the customer is confirming (stored as evidence).
     */
    public function issue(OtpPurpose $purpose, Model $subject, string $phone, string $message, ?array $payload, ?string $payloadHash, ?User $by): OtpChallenge
    {
        $code = $this->newCode();

        $challenge = DB::transaction(function () use ($purpose, $subject, $phone, $payload, $payloadHash, $by, $code) {
            OtpChallenge::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->where('purpose', $purpose->value)
                ->where('status', OtpStatus::Pending->value)
                ->update(['status' => OtpStatus::Superseded->value]);

            return OtpChallenge::create([
                'purpose' => $purpose,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'destination' => $phone,
                'code_hash' => $this->hash($code),
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'max_attempts' => (int) Setting::value('otp.max_attempts', 5),
                'expires_at' => now()->addMinutes($this->expiryMinutes()),
                'ip_address' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 255),
                'created_by' => $by?->id,
            ]);
        });

        $this->send($challenge, $code, $message);

        return $challenge;
    }

    /**
     * Send a fresh code for the same challenge. The wrong-attempt count is kept.
     */
    public function resend(OtpChallenge $challenge, string $message): OtpChallenge
    {
        $code = $this->newCode();

        DB::transaction(function () use ($challenge, $code) {
            $locked = OtpChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new BusinessRuleException('OTP_NOT_PENDING', 'This verification is no longer open. Start a new one.', 422);
            }

            if ($locked->send_count >= (int) Setting::value('otp.max_sends', 3)) {
                throw new BusinessRuleException('OTP_RESEND_LIMIT', 'The code was already sent the maximum number of times. Start a new verification.', 429);
            }

            $locked->forceFill([
                'code_hash' => $this->hash($code),
                'send_count' => $locked->send_count + 1,
                'expires_at' => now()->addMinutes($this->expiryMinutes()),
            ])->save();
        });

        $challenge->refresh();
        $this->send($challenge, $code, $message);

        return $challenge;
    }

    /**
     * Check a code. Throws a BusinessRuleException when it is not accepted.
     *
     * Must not be called inside another transaction: the attempt is committed on its own so a
     * wrong code is always counted, even though the caller's work is rolled back.
     *
     * @param  string|null  $currentPayloadHash  Hash of the data as it is now; must equal the hash at issue time.
     */
    public function verify(OtpChallenge $challenge, string $code, ?string $currentPayloadHash = null): OtpChallenge
    {
        $result = DB::transaction(function () use ($challenge, $code, $currentPayloadHash) {
            $locked = OtpChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                return ['OTP_NOT_PENDING', 'This code can no longer be used. Request a new one.'];
            }

            if ($locked->isExpired()) {
                return ['OTP_EXPIRED', 'The code has expired. Request a new one.'];
            }

            $locked->attempts++;

            if (! hash_equals($locked->code_hash, $this->hash($code))) {
                $left = $locked->max_attempts - $locked->attempts;

                if ($left <= 0) {
                    $locked->status = OtpStatus::Locked;
                }

                $locked->save();

                return $left <= 0
                    ? ['OTP_LOCKED', 'Too many wrong codes. Request a new verification.']
                    : ['OTP_INVALID', "The code is not correct. {$left} attempt(s) left."];
            }

            if ($locked->payload_hash !== null && ! hash_equals($locked->payload_hash, (string) $currentPayloadHash)) {
                $locked->status = OtpStatus::Superseded;
                $locked->save();

                return ['OTP_PAYLOAD_CHANGED', 'The details changed after the code was sent. Send a new code so the customer confirms the current details.'];
            }

            $locked->status = OtpStatus::Verified;
            $locked->verified_at = now();
            $locked->save();

            return $locked;
        });

        if (is_array($result)) {
            throw new BusinessRuleException($result[0], $result[1], 422);
        }

        return $result;
    }

    public function latestPending(Model $subject, OtpPurpose $purpose): ?OtpChallenge
    {
        return OtpChallenge::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('purpose', $purpose->value)
            ->where('status', OtpStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    public function expiryMinutes(): int
    {
        return (int) Setting::value('otp.expiry_minutes', 5);
    }

    protected function send(OtpChallenge $challenge, string $code, string $message): void
    {
        $text = strtr($message, [':code' => $code, ':minutes' => (string) $this->expiryMinutes()]);

        // Only on a developer machine, and only when explicitly switched on, the code is written to
        // the local log so OTP flows can be tested without an SMS account. Never in any other environment.
        if (app()->environment('local') && config('services.otp.log_codes_locally')) {
            Log::debug("[OTP local only] {$challenge->purpose->value} {$challenge->public_id}: {$code}");

            return;
        }

        if (! $this->sms->sendSms($challenge->destination, $text)) {
            $challenge->forceFill(['status' => OtpStatus::Superseded])->save();

            throw new BusinessRuleException('OTP_SEND_FAILED', 'The SMS could not be sent. Please try again in a moment.', 503);
        }
    }

    protected function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Keyed with APP_KEY: without the key, a copy of the table is useless for guessing codes.
     */
    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
