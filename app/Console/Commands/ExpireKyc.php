<?php

namespace App\Console\Commands;

use App\Enums\KycStatus;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * KYC confirmed longer ago than kyc.validity_days becomes expired; the customer confirms their
 * details again before a new application (hasValidKyc already refuses it, this keeps the
 * status column truthful for lists and reports).
 */
class ExpireKyc extends Command
{
    protected $signature = 'kyc:expire';

    protected $description = 'Mark KYC confirmations past their validity as expired';

    public function handle(): int
    {
        $count = Customer::withoutGlobalScope('branch')
            ->where('kyc_status', KycStatus::Verified->value)
            ->where('kyc_expires_at', '<', now())
            ->update(['kyc_status' => KycStatus::Expired->value, 'updated_at' => now()]);

        $this->info("{$count} KYC confirmation(s) expired.");

        return self::SUCCESS;
    }
}
