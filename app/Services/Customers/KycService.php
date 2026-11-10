<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\KycStatus;
use App\Enums\OtpPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\OtpService;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\DB;

/**
 * KYC confirmation: the customer proves the registered phone is theirs and confirms the
 * details on record by reading back a code sent to that phone.
 *
 * What the customer confirmed is stored with the challenge (payload), and the hash of those
 * details is stored on the customer. If staff later change any of them, KYC goes back to
 * pending (see CustomerService::update), so a loan can never rely on details the customer
 * did not confirm.
 */
class KycService
{
    use ActivityLogTrait;

    public function __construct(
        protected OtpService $otp,
    ) {}

    public function sendOtp(Customer $customer, User $by): OtpChallenge
    {
        if (! $customer->is_active) {
            throw new BusinessRuleException('CUSTOMER_INACTIVE', 'The customer is inactive.');
        }

        $challenge = $this->otp->issue(
            OtpPurpose::KycConfirmation,
            $customer,
            $customer->phone_primary,
            $this->message($customer),
            $customer->kycSnapshot(),
            $customer->kycFingerprint(),
            $by,
        );

        $this->logActivity('KYC_OTP_SENT', 'Customer', "KYC code sent to {$customer->customer_no}");

        return $challenge;
    }

    public function resendOtp(Customer $customer): OtpChallenge
    {
        $challenge = $this->pendingChallenge($customer);

        return $this->otp->resend($challenge, $this->message($customer));
    }

    public function verify(Customer $customer, string $code, User $by): Customer
    {
        $challenge = $this->pendingChallenge($customer);

        // Outside the transaction on purpose: a wrong code must stay counted (see OtpService::verify).
        $this->otp->verify($challenge, $code, $customer->kycFingerprint());

        DB::transaction(function () use ($customer) {
            $customer->forceFill([
                'kyc_status' => KycStatus::Verified,
                'kyc_verified_at' => now(),
                'kyc_expires_at' => now()->addDays((int) Setting::value('kyc.validity_days', 365)),
                'kyc_snapshot_hash' => $customer->kycFingerprint(),
            ])->save();
        });

        $this->logActivity('KYC_VERIFIED', 'Customer', "KYC verified for {$customer->customer_no}", [
            'verified_by' => $by->id,
            'challenge' => $challenge->public_id,
        ]);

        return $customer->refresh();
    }

    protected function pendingChallenge(Customer $customer): OtpChallenge
    {
        $challenge = $this->otp->latestPending($customer, OtpPurpose::KycConfirmation);

        if (! $challenge) {
            throw new BusinessRuleException('KYC_OTP_NOT_REQUESTED', 'No KYC code is waiting for this customer. Send a new code first.', 422);
        }

        return $challenge;
    }

    /**
     * The SMS tells the customer what they are confirming and warns them not to share the code,
     * which is the main defence against a staff member or caller asking for it.
     */
    protected function message(Customer $customer): string
    {
        $id = substr((string) $customer->normalizedIdNumber(), -4);

        return "CDP Capital: :code is your code to confirm your details (customer {$customer->customer_no}, ID ending {$id}) for KYC. "
            .'Valid :minutes minutes. Never share this code with anyone. If you did not request it, call your branch.';
    }
}
