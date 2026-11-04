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
     * Run a list query: one page by default, or with ?print=1 the whole filtered list in a single page.
     *
     * Print returns at most 1000 rows so a list can never pull the entire table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    protected function page($query, Request $request): LengthAwarePaginator
    {
        if (! $request->boolean('print')) {
            return $query->paginate($this->perPage($request));
        }

        $rows = $query->limit(1000)->get();

        return new LengthAwarePaginator($rows, $rows->count(), max(1, $rows->count()), 1);
    }

    /**
     * Page size from the request, never more than 100 rows at once.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->get('per_page', 15), 100));
    }
}
