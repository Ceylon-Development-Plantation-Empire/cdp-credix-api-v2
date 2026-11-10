<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /users/list: names and branches only, optionally only users holding a permission.
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class UserDirectoryRequest extends FormRequest
{
    /**
     * Any back-office user (route group user.type:admin,staff).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'permission' => ['nullable', 'string', 'max:100'],
            // e.g. ?user_type=agency for the external recovery agencies
            'user_type' => ['nullable', 'string', 'in:admin,staff,agency'],
        ];
    }
}
