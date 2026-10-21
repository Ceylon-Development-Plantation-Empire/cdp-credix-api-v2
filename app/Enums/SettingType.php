<?php

namespace App\Enums;

use Brick\Math\BigDecimal;

enum SettingType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case String = 'string';
    case Json = 'json';

    /**
     * Turn the stored text into the PHP value the code works with.
     * Decimals come back as strings so they can go straight into BigDecimal without float rounding.
     */
    public function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Integer => (int) $value,
            self::Decimal => (string) BigDecimal::of($value),
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::String => $value,
            self::Json => json_decode($value, true),
        };
    }

    /**
     * Turn a validated input value into the text stored in the settings table.
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            self::Json => json_encode($value),
            default => (string) $value,
        };
    }
}
