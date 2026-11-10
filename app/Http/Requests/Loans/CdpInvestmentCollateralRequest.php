<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A CDP Core investment as collateral: the NIC of its owner and the policy number. Everything else
 * (value, product, maturity) is read from CDP Core.
 */
class CdpInvestmentCollateralRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nic' => ['required', 'string', 'max:20'],
            'policy_number' => ['required', 'string', 'max:100'],
        ];
    }
}
