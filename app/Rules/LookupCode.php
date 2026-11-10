<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\LookupType;
use App\Support\Lookups\LookupRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The value must be a selectable code of one lookup list (active and inside its effective period).
 *
 * This is the "right list" check the lookups foreign key cannot make on its own (the FK only
 * proves the value exists in some list).
 *
 * Usage: 'branch_type' => ['required', new LookupCode(LookupType::BranchType)]
 */
final class LookupCode implements ValidationRule
{
    public function __construct(
        private readonly LookupType $type,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(LookupRegistry::class)->isSelectable($this->type, $value)) {
            $fail("The selected :attribute is not a valid {$this->type->label()} value.");
        }
    }
}
