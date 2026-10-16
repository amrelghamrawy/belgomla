<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\WaGateway\WaGatewayDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
});

function gatewaySend(): App\Modules\Messaging\Data\DriverResult
{
    return test()->inTenant(function () {
        $message = OutboundMessage::factory()->create(['provider' => Provider::WaGateway]);

        return app(WaGatewayDriver::class)->send($message, new MessagePayload('+201012345678', MessagePurpose::Otp, 'رمز التحقق: 123456'));
    });
}

function connectedStore(): WhatsAppConnection
{
    return test()->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
}

test('D70 a send through the store number is final with the whatsapp message id', function (): void {
    $connection = connectedStore();
    Http::fake(["127.0.0.1:3100/sessions/{$connection->session_key}/messages" => Http::response(['id' => '3EB0ABC'])]);

    $result = gatewaySend();

    expect([$result->ok, $result->final, $result->providerMessageId])->toBe([true, true, '3EB0ABC']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer gateway-token')
        && $request->data() === ['to' => '+201012345678', 'text' => 'رمز التحقق: 123456']);
});

test('BR-CU07 a number the gateway reports as not on whatsapp fails with notOnWhatsApp', function (): void {
    connectedStore();
    Http::fake(['*' => Http::response(['error' => 'not_on_whatsapp'], 422)]);

    $result = gatewaySend();

    expect([$result->ok, $result->errorCode, $result->notOnWhatsApp])->toBe([false, 'not_on_whatsapp', true]);
});

test('D70 a refused or broken gateway answer fails the message at once', function (int $status, array $body, string $code): void {
    connectedStore();
    Http::fake(['*' => Http::response($body, $status)]);

    $result = gatewaySend();

    expect([$result->ok, $result->final, $result->errorCode, $result->notOnWhatsApp])->toBe([false, true, $code, false]);
})->with([
    'session not open' => [409, ['error' => 'not_open'], 'session_not_open'],
    'server error' => [500, ['error' => 'server_error'], 'gateway_error'],
    'unauthenticated' => [401, ['error' => 'unauthenticated'], 'gateway_error'],
    'success without an id' => [200, [], 'gateway_error'],
]);

test('D70 an unreachable gateway fails the message so the platform number takes over', function (): void {
    connectedStore();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(gatewaySend()->errorCode)->toBe('gateway_unreachable');
});

test('D70 a send over the store number limit asks to retry after the gateway delay', function (array $body, int $seconds): void {
    connectedStore();
    Http::fake(['*' => Http::response($body, 429)]);

    $result = gatewaySend();

    expect([$result->ok, $result->final, $result->retryAfter])->toBe([true, false, $seconds]);
})->with([
    'with retry_after' => [['error' => 'rate_limited', 'retry_after' => 42], 42],
    'without retry_after' => [['error' => 'rate_limited'], 60],
]);

test('D70 a store whose number dropped since the message was logged fails without calling the gateway', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->loggedOut()->create());
    Http::fake();

    expect(gatewaySend()->errorCode)->toBe('session_not_open');
    Http::assertNothingSent();
});

test('the status of a store number message is the id it was sent with', function (): void {
    $sent = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0ABC')->create(['provider' => Provider::WaGateway]));
    $queued = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));
    $driver = app(WaGatewayDriver::class);

    expect([$driver->status($sent)->providerMessageId, $driver->status($sent)->final])->toBe(['3EB0ABC', true])
        ->and($driver->status($queued)->errorCode)->toBe('missing_message_id')
        ->and($driver->provider())->toBe(Provider::WaGateway);
});
