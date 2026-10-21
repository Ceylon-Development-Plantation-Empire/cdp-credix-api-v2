<?php

namespace App\Http\Requests\Groups;

use App\Enums\BorrowerGroupStatus;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) needs every required field; update (PUT) accepts any subset.
 * A group cannot move branch after it is created (its members are of that branch).
 */
class BorrowerGroupRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'min:3', 'max:150'],
            'branch_id' => $creating
                ? ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)]
                : ['prohibited'],
            'formed_on' => [$presence, 'date_format:Y-m-d', 'before_or_equal:today'],
            'meeting_day' => ['nullable', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::enum(BorrowerGroupStatus::class)],
        ];
    }
}
