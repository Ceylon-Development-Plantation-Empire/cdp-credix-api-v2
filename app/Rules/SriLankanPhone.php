<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Sri Lankan phone number in any common format (077…, +94 77 …, 77…).
 * With $mobileOnly the number must be a mobile, because OTPs and SMS go to it.
 */
class SriLankanPhone implements ValidationRule
{
    public function __construct(
        protected bool $mobileOnly = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNormalizer::normalize($value) === null) {
            $fail('The :attribute must be a valid Sri Lankan phone number.');

            return;
        }

        if ($this->mobileOnly && ! PhoneNormalizer::isMobile($value)) {
            $fail('The :attribute must be a mobile number (SMS and OTPs are sent to it).');
        }
    }
}
