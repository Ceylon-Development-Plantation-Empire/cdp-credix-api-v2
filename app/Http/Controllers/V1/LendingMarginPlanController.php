<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\LookupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\LendingMarginPlanIndexRequest;
use App\Http\Requests\Catalog\StoreLendingMarginPlanRequest;
use App\Http\Requests\Catalog\UpdateLendingMarginPlanRequest;
use App\Http\Resources\Catalog\LendingMarginPlanResource;
use App\Models\LendingMarginPlan;
use App\Services\Catalog\LendingMarginPlanService;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Lending-margin plans: how much can be lent against a collateral, per named plan
 * ("Investment Plan 2 — 60 %"). Head office maintains them; officers pick one (optionally) when
 * they add a collateral to a loan.
 *
 * GET /lending-margin-plans/options serves the dropdown to any back-office user; the admin
 * endpoints need "Lending Margin Index" / "Lending Margin Manage".
 */
class LendingMarginPlanController extends Controller implements HasMiddleware
{
    public function __construct(
        protected LendingMarginPlanService $plans,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Lending Margin Index', only: ['index', 'show']),
            new Middleware('permission:Lending Margin Manage', only: ['store', 'update']),
        ];
    }

    /**
     * GET /lending-margin-plans?collateral_type=&is_active=&search= (inactive ones included).
     */
    public function index(LendingMarginPlanIndexRequest $request): JsonResponse
    {
        $plans = $this->filtered($request)
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Lending margin plans retrieved successfully',
            'data' => LendingMarginPlanResource::collection($plans),
        ], 200);
    }

    /**
     * GET /lending-margin-plans/options?collateral_type=: active plans [{code, name, percentage}] for a dropdown.
     */
    public function options(LendingMarginPlanIndexRequest $request): JsonResponse
    {
        $plans = $this->filtered($request)->active()->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Lending margin plan options retrieved successfully',
            'data' => $plans->map(fn (LendingMarginPlan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'collateral_type' => $plan->collateral_type,
                'percentage' => (string) $plan->percentage,
            ])->values(),
        ], 200);
    }

    /**
     * GET /lending-margin-plans/{code}.
     */
    public function show(string $code): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Lending margin plan retrieved successfully',
            'data' => new LendingMarginPlanResource(LendingMarginPlan::query()->where('code', $code)->firstOrFail()),
        ], 200);
    }

    /**
     * POST /lending-margin-plans.
     */
    public function store(StoreLendingMarginPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Lending margin plan created successfully',
            'data' => new LendingMarginPlanResource($plan),
        ], 201);
    }

    /**
     * PUT /lending-margin-plans/{code}.
     */
    public function update(UpdateLendingMarginPlanRequest $request, string $code): JsonResponse
    {
        $plan = $this->plans->update(LendingMarginPlan::query()->where('code', $code)->firstOrFail(), $request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Lending margin plan updated successfully',
            'data' => new LendingMarginPlanResource($plan),
        ], 200);
    }

    /**
     * @return Builder<LendingMarginPlan>
     */
    protected function filtered(LendingMarginPlanIndexRequest $request): Builder
    {
        $type = $request->validated('collateral_type');
        $search = $request->validated('search');

        return LendingMarginPlan::query()
            // An unknown type simply has no plans (empty list, not an error).
            ->when($type, fn (Builder $q) => $q->where('collateral_type_id', $this->typeId((string) $type) ?? 0))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('collateral_type_id')->orderBy('sort_order')->orderBy('name');
    }

    protected function typeId(string $code): ?int
    {
        foreach (app(LookupRegistry::class)->values(LookupType::CollateralType) as $value) {
            if ($value['code'] === $code) {
                return $value['id'];
            }
        }

        return null;
    }
}
