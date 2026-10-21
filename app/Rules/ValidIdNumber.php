<?php

namespace App\Rules;

use App\Enums\IdType;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The ID number must be valid for the chosen ID type (old or new NIC, passport, driving licence).
 * Reads id_type from the same request; when id_type itself is invalid this rule stays quiet
 * and the id_type error is shown instead.
 */
class ValidIdNumber implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(
        protected ?IdType $fallbackType = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = isset($this->data['id_type']) ? IdType::tryFrom((string) $this->data['id_type']) : $this->fallbackType;

        if (! $type) {
            return;
        }

        if (! is_string($value) || $type->normalize($value) === null) {
            $fail(match ($type) {
                IdType::Nic => 'The NIC must be 9 digits followed by V or X, or 12 digits, with a valid birth day.',
                IdType::Passport => 'The passport number is not in a valid format.',
                IdType::DrivingLicence => 'The driving licence number is not in a valid format.',
            });
        }
    }
}
