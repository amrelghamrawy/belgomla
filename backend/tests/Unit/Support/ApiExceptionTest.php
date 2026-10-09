<?php

declare(strict_types=1);

use App\Support\ApiException;

test('factories map to the documented statuses and codes', function (ApiException $e, int $status, string $code): void {
    expect($e->status)->toBe($status)
        ->and($e->errorCode)->toBe($code);
})->with([
    'not found' => [fn (): ApiException => ApiException::notFound(), 404, 'not_found'],
    'forbidden' => [fn (): ApiException => ApiException::forbidden('x', 'm'), 403, 'x'],
    'conflict' => [fn (): ApiException => ApiException::conflict('x', 'm', ['d' => 1]), 409, 'x'],
    'unprocessable' => [fn (): ApiException => ApiException::unprocessable('x', 'm'), 422, 'x'],
    'unavailable' => [fn (): ApiException => ApiException::unavailable('x', 'm'), 503, 'x'],
]);

test('the default not found message is Arabic', function (): void {
    expect(ApiException::notFound()->getMessage())->toBe('العنصر غير موجود');
});

test('details and items are carried to the renderer', function (): void {
    $this->tenantJson('GET', '/api/store/tenant'); // boot the app with a tenant host

    Illuminate\Support\Facades\Route::get('/api/store/_err', fn () => throw ApiException::unprocessable('checkout_failed', 'm', ['mov' => 1], [['sku_id' => 1]]));

    $this->tenantJson('GET', '/api/store/_err')
        ->assertUnprocessable()
        ->assertExactJson(['error' => ['code' => 'checkout_failed', 'message' => 'm', 'details' => ['mov' => 1], 'items' => [['sku_id' => 1]]]]);
});
