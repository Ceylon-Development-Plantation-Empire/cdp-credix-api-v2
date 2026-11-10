<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /forgot-password: the email of the account that forgot its password.
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * Public endpoint (rate limited by the "password-reset" limiter).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
