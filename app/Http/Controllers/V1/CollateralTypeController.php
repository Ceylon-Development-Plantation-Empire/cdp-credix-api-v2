<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Http\JsonResponse;

/**
 * GET /collateral-types: everything the loan security step needs in one call: each selectable
 * collateral type with its fields (rules and whether required), proof documents, how it is valued,
 * and what proves it is perfected.
 *
 * The types themselves are managed as lookups of type collateral_type (/lookups, "Lookup Manage").
 */
class CollateralTypeController extends Controller
{
    public function index(CollateralTypes $types): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Collateral types retrieved successfully',
            'data' => $types->selectable(),
        ], 200);
    }
}
