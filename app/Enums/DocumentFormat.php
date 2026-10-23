<?php

declare(strict_types=1);

namespace App\Enums;

/** The file formats a document type may accept (stored on the type as a comma separated list). */
enum DocumentFormat: string
{
    case Pdf = 'pdf';
    case Jpg = 'jpg';
    case Jpeg = 'jpeg';
    case Png = 'png';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
