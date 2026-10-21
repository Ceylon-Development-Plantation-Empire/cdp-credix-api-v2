<?php

namespace App\Enums;

/**
 * How the document types of one requirement group are satisfied.
 *
 *   any → at least one of the listed types (identity: NIC or passport or driving licence)
 *   all → every listed type (residency: GN letter and DS letter)
 */
enum RequirementRule: string
{
    case Any = 'any';
    case All = 'all';
}
