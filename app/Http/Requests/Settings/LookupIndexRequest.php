<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /lookups?type=…: which list to show.
 */
class LookupIndexRequest extends FormRequest
{
    /**
     * Permission "Lookup Index" is checked by the controller middleware.
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
            'type' => ['required', Rule::enum(LookupType::class)],
        ];
    }
}
