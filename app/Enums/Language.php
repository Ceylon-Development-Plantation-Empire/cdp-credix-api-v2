<?php

namespace App\Enums;

/**
 * Language the customer receives SMS and documents in.
 */
enum Language: string
{
    case English = 'en';
    case Sinhala = 'si';
    case Tamil = 'ta';
}
