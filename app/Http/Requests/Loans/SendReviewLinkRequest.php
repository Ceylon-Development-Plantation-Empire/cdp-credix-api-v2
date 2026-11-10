<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Send the review link to everyone on the loan who has not confirmed, or only to the people named.
 */
class SendReviewLinkRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_public_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'customer_public_ids.*' => ['string', 'ulid'],
        ];
    }
}
