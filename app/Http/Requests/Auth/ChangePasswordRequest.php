<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /password/change: the signed-in user's own password.
 *
 * SECURITY: the new password follows Password::defaults() (AppServiceProvider: at least 12
 * characters, upper and lower case, a number and a symbol; in production also not found in a
 * known data breach) and must differ from the current one.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * Any signed-in user may change their own password.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', 'different:current_password', Password::defaults()],
        ];
    }
}
