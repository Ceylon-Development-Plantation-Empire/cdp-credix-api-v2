<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreChargeTypeRequest;
use App\Http\Requests\Catalog\UpdateChargeTypeRequest;
use App\Http\Resources\Catalog\ChargeTypeResource;
use App\Models\ChargeType;
use App\Services\Catalog\ChargeTypeService;
use App\Traits\ActivityLogTrait;
use App\Traits\DeletesRecords;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Fee and charge types (owner decision: fee types are data, grouped for display). Products
 * pick from the active ones; see App\Enums\ChargeBehaviour for what the engine does with each.
 */
class ChargeTypeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, DeletesRecords, HandlesApiErrors;

    public function __construct(
        protected ChargeTypeService $chargeTypes,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Charge Type Index', only: ['index', 'show']),
            new Middleware('permission:Charge Type Create', only: ['store']),
            new Middleware('permission:Charge Type Update', only: ['update']),
            new Middleware('permission:Charge Type Delete', only: ['destroy']),
        ];
    }

    /**
     * GET /charge-types (inactive ones included, in display order).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Charge types retrieved successfully',
            'data' => ChargeTypeResource::collection(ChargeType::query()->orderBy('sort_order')->orderBy('name')->get()),
        ], 200);
    }

    /**
     * GET /charge-types/{code}.
     */
    public function show(string $code): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Charge type retrieved successfully',
            'data' => new ChargeTypeResource(ChargeType::query()->where('code', $code)->firstOrFail()),
        ], 200);
    }

    /**
     * POST /charge-types.
     */
    public function store(StoreChargeTypeRequest $request): JsonResponse
    {
        $type = $this->chargeTypes->create($request->safe()->except('behaviour'), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Charge type created successfully',
            'data' => new ChargeTypeResource($type),
        ], 201);
    }

    /**
     * PUT /charge-types/{code}.
     */
    public function update(UpdateChargeTypeRequest $request, string $code): JsonResponse
    {
        $type = $this->chargeTypes->update(ChargeType::query()->where('code', $code)->firstOrFail(), $request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Charge type updated successfully',
            'data' => new ChargeTypeResource($type),
        ], 200);
    }

    public function destroy(string $code): JsonResponse
    {
        return $this->destroyRecord(ChargeType::query()->where('code', $code)->first(), 'charge type', 'ChargeType', fn (ChargeType $type) => match (true) {
            (bool) $type->is_system => 'A system charge type cannot be deleted.',
            $type->productCharges()->exists() => 'Products use this charge type. Deactivate it instead.',
            default => 'Charge types are deactivated, never deleted. Switch it off with PUT /charge-types/{code}.',
        });
    }
}
