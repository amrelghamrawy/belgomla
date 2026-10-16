<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Middleware\VerifyWaGatewaySignature;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    useWaGateway();
    $this->freezeTime();
    Route::post('/api/_signed', fn (): string => 'ok')->middleware(VerifyWaGatewaySignature::class);
});

function signedPost(string $body, ?string $signature, ?string $timestamp): Illuminate\Testing\TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($timestamp !== null) {
        $server['HTTP_X_GATEWAY_TIMESTAMP'] = $timestamp;
    }

    if ($signature !== null) {
        $server['HTTP_X_GATEWAY_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/_signed', server: $server, content: $body);
}

test('a body signed with the secret over timestamp and body passes', function (): void {
    $timestamp = (string) now()->getTimestamp();

    signedPost('{"a":1}', hash_hmac('sha256', $timestamp.'.{"a":1}', 'gateway-secret'), $timestamp)->assertOk();
});

test('tasks/12 lesson 5 an unsigned, tampered or stale call is refused', function (string $body, ?string $signedBody, ?string $timestamp): void {
    $signature = $signedBody === null ? null : hash_hmac('sha256', $timestamp.'.'.$signedBody, 'gateway-secret');

    signedPost($body, $signature, $timestamp)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with([
    'no headers' => ['{}', null, null],
    'no timestamp' => ['{}', '{}', null],
    'tampered body' => ['{"a":2}', '{"a":1}', (string) now()->getTimestamp()],
    'timestamp not a number' => ['{}', '{}', 'yesterday'],
    'stale timestamp' => ['{}', '{}', (string) (now()->getTimestamp() - 301)],
]);

test('an empty secret refuses every call, even one signed with the empty secret', function (): void {
    config(['messaging.wa_gateway.webhook_secret' => '']);
    $timestamp = (string) now()->getTimestamp();

    signedPost('{}', hash_hmac('sha256', $timestamp.'.{}', ''), $timestamp)->assertUnauthorized();
});
