<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\LookupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LookupIndexRequest;
use App\Http\Requests\Settings\StoreLookupRequest;
use App\Http\Requests\Settings\UpdateLookupRequest;
use App\Http\Resources\Settings\LookupResource;
use App\Models\Lookup;
use App\Services\Settings\LookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Code lists managed by the business (banks, categories, relationships, branch / employee types,
 * designation levels, custom fields). See App\Enums\LookupType for the lists.
 *
 * GET /lookups/options/{type} serves dropdowns to any back-office user; the admin endpoints need
 * "Lookup Index" / "Lookup Manage".
 */
class LookupController extends Controller implements HasMiddleware
{
    public function __construct(
        protected LookupService $lookups,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Lookup Index', only: ['types', 'index', 'show']),
            new Middleware('permission:Lookup Manage', only: ['store', 'update']),
        ];
    }

    /**
     * GET /lookups/types: the lists and their names.
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Lookup lists retrieved successfully',
            'data' => array_map(fn (LookupType $type) => [
                'type' => $type->value,
                'label' => $type->label(),
                'custom_fields' => $type->isCustomFieldList(),
            ], LookupType::cases()),
        ], 200);
    }

    /**
     * GET /lookups?type=bank: every value of a list, inactive ones included (admin screen).
     */
    public function index(LookupIndexRequest $request): JsonResponse
    {
        $type = LookupType::from($request->validated('type'));

        return response()->json([
            'status' => 'success',
            'message' => 'Lookup values retrieved successfully',
            'data' => LookupResource::collection(Lookup::query()->ofType($type)->orderBy('sort_order')->orderBy('label')->get()),
        ], 200);
    }

    /**
     * GET /lookups/options/{type}: selectable values [{code, label}] for a dropdown.
     */
    public function options(string $type): JsonResponse
    {
        $list = LookupType::tryFrom($type);

        if ($list === null) {
            return response()->json(['status' => 'error', 'code' => 'NOT_FOUND', 'message' => 'Unknown lookup list'], 404);
        }

        $values = Lookup::query()->ofType($list)->selectable()->orderBy('sort_order')->orderBy('label')->get(['code', 'label']);

        return response()->json([
            'status' => 'success',
            'message' => 'Lookup options retrieved successfully',
            'data' => $values->map(fn (Lookup $l) => ['code' => $l->code, 'label' => $l->label])->values(),
        ], 200);
    }

    /**
     * GET /lookups/{id}.
     */
    public function show(int $id): JsonResponse
    {
        $lookup = Lookup::query()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'message' => 'Lookup value retrieved successfully',
            'data' => new LookupResource($lookup),
        ], 200);
    }

    /**
     * POST /lookups.
     */
    public function store(StoreLookupRequest $request): JsonResponse
    {
        $lookup = $this->lookups->create($request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Lookup value created successfully',
            'data' => new LookupResource($lookup),
        ], 201);
    }

    /**
     * PUT /lookups/{id}.
     */
    public function update(UpdateLookupRequest $request, int $id): JsonResponse
    {
        $lookup = $this->lookups->update(Lookup::query()->findOrFail($id), $request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Lookup value updated successfully',
            'data' => new LookupResource($lookup),
        ], 200);
    }
}
