<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Levels of the organisation structure held in org_units. Geography runs country → province →
 * zone → region; a company group stands on its own. Branches point at these units.
 */
enum OrgUnitType: string
{
    case Group = 'group';
    case Country = 'country';
    case Province = 'province';
    case Zone = 'zone';
    case Region = 'region';
}
