<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LookupType;
use App\Rules\LookupCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:branches,code|max:20',
            'group_id' => 'nullable|exists:org_units,id,unit_type,group',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'zone_id' => 'required|exists:org_units,id,unit_type,zone',
            'region_id' => 'required|exists:org_units,id,unit_type,region',
            'province_id' => 'required|exists:org_units,id,unit_type,province',
            'phone_primary' => 'required|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'fax' => 'nullable|string|max:20',
            'opening_date' => 'required|date',
            'branch_type' => ['required', 'string', new LookupCode(LookupType::BranchType)],
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
            'is_head_office' => 'sometimes|boolean',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();
        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for '.$fieldErrors->first()['field'].'.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
