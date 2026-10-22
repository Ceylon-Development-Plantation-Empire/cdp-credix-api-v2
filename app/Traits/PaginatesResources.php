<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

trait PaginatesResources
{
    /**
     * Shape a paginated query for the response "data" key.
     *
     * Laravel's default paginator JSON carries links, path and page URLs the frontend never uses.
     * We send the transformed items and only the numbers needed to draw pagination.
     *
     * @param  class-string<JsonResource>  $resource
     * @return array{items: mixed, meta: array<string, int>}
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource): array
    {
        return [
            'items' => $resource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Page size from the request, never more than 100 rows at once.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->get('per_page', 15), 100));
    }
}
