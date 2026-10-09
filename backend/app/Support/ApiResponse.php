<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Builds the response envelopes defined in 08-api-contract.md §0.
 */
final class ApiResponse
{
    public static function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    public static function created(mixed $data): JsonResponse
    {
        return self::data($data, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param  callable|null  $map  transforms each item
     */
    public static function paginated(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = $paginator->getCollection();
        if ($map) {
            $items = $items->map($map);
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function error(int $status, string $code, string $message, ?array $details = null, ?array $items = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        if ($items !== null) {
            $error['items'] = $items;
        }

        return response()->json(['error' => $error], $status);
    }
}
