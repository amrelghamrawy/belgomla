<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Middleware\VerifyHypersenderWebhook;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['messaging.webhook_secret' => 'hook-secret']);
    Route::post('/api/_hook', fn (): string => 'ok')->middleware(VerifyHypersenderWebhook::class);
});

test('the configured secret in the Authorization header passes', function (string $header): void {
    $this->postJson('http://hooks.gomal.test/api/_hook', [], ['Authorization' => $header])->assertOk();
})->with([
    'raw secret' => ['hook-secret'],
    'bearer secret' => ['Bearer hook-secret'],
]);

test('a wrong or missing secret is 401 unauthenticated', function (array $headers): void {
    $this->postJson('http://hooks.gomal.test/api/_hook', [], $headers)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'يجب تسجيل الدخول']]);
})->with([
    'wrong secret' => [['Authorization' => 'not-the-secret']],
    'prefix of the secret' => [['Authorization' => 'hook']],
    'missing header' => [[]],
]);

test('every webhook is refused while no secret is configured', function (): void {
    config(['messaging.webhook_secret' => '']);

    $this->postJson('http://hooks.gomal.test/api/_hook', [], ['Authorization' => ''])->assertUnauthorized();
});
