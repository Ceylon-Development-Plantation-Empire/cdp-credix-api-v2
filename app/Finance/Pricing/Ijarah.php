<?php

declare(strict_types=1);

namespace App\Finance\Pricing;

/**
 * Ijarah (Islamic lease).
 *
 * The numbers work like a finance lease: rentals are set so CDP recovers the asset cost
 * plus its return over the term. What makes it Shariah compliant is the contract, not
 * the arithmetic: CDP owns the asset during the lease (and bears ownership risk such as
 * Takaful and major repairs), the rent is for using the asset, and ownership passes to the
 * customer at the end by a separate gift or sale (agreement template).
 *
 * The "profit" column of each line is the rental income part of the rent.
 */
class Ijarah extends Lease {}
