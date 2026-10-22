<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /login/verify-otp: second sign-in step while setting security.staff_login_otp is on.
 */
class VerifyLoginOtpRequest extends FormRequest
{
    /**
     * Public endpoint (rate limited by the "login" limiter).
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
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
