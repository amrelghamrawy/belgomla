<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  (callable(mixed): mixed)|null  $map  transforms each item
     */
    public static function paginated(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = collect($paginator->items());

        if ($map !== null) {
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

    public static function validation(ValidationException $exception): JsonResponse
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            $details[(string) $field] = array_values(array_map(
                fn (mixed $message): string => is_string($message) ? $message : '',
                is_array($messages) ? $messages : [$messages],
            ));
        }

        return self::error(422, 'validation_failed', 'بعض البيانات غير صحيحة', $details);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  list<array<string, mixed>>|null  $items
     */
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
