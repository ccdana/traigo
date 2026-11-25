<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

final class Responses
{
    /**
     * Una lista paginada: `{ data, meta: { current_page, last_page, per_page,
     * total } }`.
     *
     * @param  callable(mixed): array<string, mixed>  $item
     */
    public static function paginated(LengthAwarePaginator $page, callable $item): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($item)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
