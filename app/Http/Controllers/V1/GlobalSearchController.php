<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\Customers\GlobalSearchService;
use App\Support\Mask;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * One person across every module, from an identification document (Customer 360).
 *
 * Read-only. The search is recorded in the activity log (the ID number masked): it can pull up
 * a named person's whole financial position from a number printed on their ID card, so who ran
 * it and when is worth keeping.
 */
class GlobalSearchController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors;

    public function __construct(
        protected GlobalSearchService $search,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Global Search', only: ['search']),
        ];
    }

    public function idTypes()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Identification types retrieved successfully',
            'data' => GlobalSearchService::ID_TYPES,
        ], 200);
    }

    public function search(Request $request)
    {
        try {
            $data = $request->validate([
                'id_type' => ['required', 'string', 'max:30'],
                'id_number' => ['required', 'string', 'max:50'],
            ]);

            $type = GlobalSearchService::idTypeFrom($data['id_type']);

            if (! $type) {
                throw ValidationException::withMessages(['id_type' => ['The identification type must be one of: '.implode(', ', GlobalSearchService::ID_TYPES).'.']]);
            }

            $result = $this->search->search($type, trim($data['id_number']));

            $this->logActivity('INDEX', 'GlobalSearch', 'Global search performed', [
                'id_type' => $type->value,
                'id_number' => Mask::idNumber(trim($data['id_number'])),
                'found' => $result['found'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $result['found'] ? 'Person found' : 'No person found for that identification',
                'data' => $result,
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to run the global search', $th);
        }
    }
}
