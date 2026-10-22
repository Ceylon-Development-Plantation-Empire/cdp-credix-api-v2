<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Employee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The employee ID number must not belong to another employee.
 *
 * The number is encrypted at rest, so a plain "unique:employees,id_number" cannot work: the check
 * runs on the blind index (employees.id_number_hash) instead.
 */
final class UniqueEmployeeIdNumber implements ValidationRule
{
    /**
     * @param  int|null  $ignoreEmployeeId  The employee being updated (its own number is allowed).
     */
    public function __construct(
        private readonly ?int $ignoreEmployeeId = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $taken = Employee::query()
            ->where('id_number_hash', Employee::idNumberHash($value))
            ->when($this->ignoreEmployeeId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('The :attribute has already been taken.');
        }
    }
}
