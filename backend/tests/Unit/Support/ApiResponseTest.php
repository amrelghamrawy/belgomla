<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;

test('data envelope', function (): void {
    $response = ApiResponse::data(['a' => 1]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['data' => ['a' => 1]]);
});

test('created and no content', function (): void {
    expect(ApiResponse::created(['id' => 1])->getStatusCode())->toBe(201)
        ->and(ApiResponse::noContent()->getStatusCode())->toBe(204);
});

test('paginated envelope maps items and exposes meta', function (): void {
    $paginator = new LengthAwarePaginator(collect([['n' => 1], ['n' => 2]]), total: 5, perPage: 2, currentPage: 2);

    $data = ApiResponse::paginated($paginator, fn (array $row): int => $row['n'] * 10)->getData(true);

    expect($data)->toBe([
        'data' => [10, 20],
        'meta' => ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
    ]);
});

test('error envelope omits empty details and items', function (): void {
    expect(ApiResponse::error(409, 'conflict', 'msg')->getData(true))
        ->toBe(['error' => ['code' => 'conflict', 'message' => 'msg']]);

    expect(ApiResponse::error(422, 'checkout_failed', 'msg', ['mov' => 1], [['sku_id' => 3]])->getData(true))
        ->toBe(['error' => ['code' => 'checkout_failed', 'message' => 'msg', 'details' => ['mov' => 1], 'items' => [['sku_id' => 3]]]]);
});
