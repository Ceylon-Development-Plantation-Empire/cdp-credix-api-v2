<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers\Concerns;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\IdType;
use App\Enums\Language;
use App\Rules\SriLankanPhone;
use App\Rules\ValidIdNumber;
use App\Support\BusinessDate;
use App\Support\SriLanka;
use Illuminate\Validation\Rule;

/**
 * Customer field rules shared by create and update.
 *
 * Cross-record rules (duplicate ID, NIC vs date of birth, branch access, age) are checked
 * by CustomerService, because they need the database or the logged-in user.
 */
trait CustomerFieldRules
{
    /**
     * @param  string  $presence  'required' for create, 'sometimes' for update.
     * @return array<string, mixed>
     */
    protected function customerRules(string $presence): array
    {
        // Names: letters (any script, so Sinhala and Tamil names work), spaces, dots, apostrophes and dashes.
        $name = 'regex:/^[\pL\pM][\pL\pM\s.\'-]*$/u';

        return [
            'branch_id' => [$presence, 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'id_type' => [$presence, Rule::enum(IdType::class)],
            'id_number' => [$presence, 'string', 'max:30', new ValidIdNumber],
            'full_name' => [$presence, 'string', 'min:3', 'max:150', $name],
            'name_with_initials' => [$presence, 'string', 'min:2', 'max:100', $name],
            'date_of_birth' => [$presence, 'date_format:Y-m-d', 'before:'.BusinessDate::today()->toDateString(), 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'preferred_language' => ['sometimes', Rule::enum(Language::class)],

            'phone_primary' => [$presence, 'string', new SriLankanPhone(mobileOnly: true)],
            'phone_secondary' => ['nullable', 'string', new SriLankanPhone],
            'whatsapp_number' => ['nullable', 'string', new SriLankanPhone(mobileOnly: true)],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],

            'address_line_1' => [$presence, 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:150'],
            'city' => [$presence, 'string', 'max:100'],
            'gn_division' => [$presence, 'string', 'max:100'],
            'ds_division' => [$presence, 'string', 'max:100'],
            'district' => [$presence, Rule::in(SriLanka::DISTRICTS)],
            'province' => [$presence, Rule::in(SriLanka::PROVINCES)],
            'postal_code' => ['nullable', 'digits:5'],

            'employment_status' => [$presence, Rule::enum(EmploymentStatus::class)],
            'occupation' => ['nullable', 'required_if:employment_status,employed', 'string', 'max:100'],
            'employer_name' => ['nullable', 'required_if:employment_status,employed', 'string', 'max:150'],
            'employer_address' => ['nullable', 'string', 'max:255'],
            'employer_phone' => ['nullable', 'string', new SriLankanPhone],
            'employer_email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'business_name' => ['nullable', 'required_if:employment_status,self_employed', 'string', 'max:150'],
            'business_registration_no' => ['nullable', 'string', 'max:50'],
            'business_nature' => ['nullable', 'string', 'max:100'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', new SriLankanPhone],
            'business_email' => ['nullable', 'string', 'email:rfc', 'max:150'],

            'recommended_by_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('is_active', true)],
        ];
    }
}
