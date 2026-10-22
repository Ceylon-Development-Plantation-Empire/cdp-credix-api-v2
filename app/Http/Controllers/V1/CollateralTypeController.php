<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\LendingMarginPlan;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Http\JsonResponse;

/**
 * GET /collateral-types: everything the loan security step needs in one call: each selectable
 * collateral type with its fields (rules and whether required), proof documents, how it is valued,
 * what proves it is perfected, and its active lending-margin plans.
 *
 * The types themselves are managed as lookups of type collateral_type (/lookups, "Lookup Manage").
 */
class CollateralTypeController extends Controller
{
    public function index(CollateralTypes $types): JsonResponse
    {
        $plans = LendingMarginPlan::query()->active()->orderBy('sort_order')->orderBy('name')->get()->groupBy('collateral_type');

        return response()->json([
            'status' => 'success',
            'message' => 'Collateral types retrieved successfully',
            'data' => array_map(fn (array $type) => $type + [
                'lending_margin_plans' => ($plans[$type['code']] ?? collect())->map(fn (LendingMarginPlan $plan) => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'percentage' => (string) $plan->percentage,
                ])->values()->all(),
            ], $types->selectable()),
        ], 200);
    }
}
