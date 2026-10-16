<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Jobs\ReconcileWaGateway;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| POST /api/webhooks/wa-gateway on hooks.{base_domain} (D70, tasks/12), HMAC-signed by wa-gateway.
*/

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
    $this->freezeSecond();
});

/**
 * @param  array<string, mixed>  $data
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function gatewayHook(string $event, ?string $sessionKey, array $data = [], ?string $secret = 'gateway-secret', ?int $timestamp = null): TestResponse
{
    app(TenantContext::class)->clear();
    $body = (string) json_encode(['event' => $event, 'session_key' => $sessionKey, 'data' => (object) $data, 'sent_at' => now()->toIso8601ZuluString()]);
    $timestamp ??= now()->getTimestamp();

    return test()->call('POST', 'http://hooks.gomal.test/api/webhooks/wa-gateway', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_GATEWAY_SIGNATURE' => $secret === null ? '' : hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], content: $body);
}

function connectionRow(WhatsAppConnection $connection): WhatsAppConnection
{
    return test()->inTenant(fn (): WhatsAppConnection => $connection->fresh() ?? throw new RuntimeException('gone'));
}

test('D70 a scanned qr connects the store number', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());

    gatewayHook('session.open', $connection->session_key, ['phone' => '+201001234567'])
        ->assertOk()
        ->assertExactJson(['data' => ['received' => true]]);

    expect(connectionRow($connection))
        ->status->toBe(ConnectionStatus::Connected)
        ->phone->toBe('+201001234567')
        ->and(connectionRow($connection)->connected_at?->equalTo(now()))->toBeTrue();
});

test('tasks/12 lesson 4 a fatal drop marks the number logged out with the reason', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    gatewayHook('session.closed', $connection->session_key, ['reason' => 'logged_out', 'fatal' => true])->assertOk();

    expect(connectionRow($connection))
        ->status->toBe(ConnectionStatus::LoggedOut)
        ->disconnect_reason->toBe('logged_out');
});

test('a delivery receipt of a store number message marks it delivered', function (string $status): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0ABC')->create(['provider' => Provider::WaGateway]));

    gatewayHook('message.status', $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create())->session_key, ['id' => '3EB0ABC', 'status' => $status])
        ->assertOk();

    $this->assertDatabaseHas('outbound_messages', ['id' => $message->id, 'status' => 'delivered', 'body' => null]);
})->with(['delivered', 'read']);

test('BR-CU07 a failed receipt of a store number message falls back to the platform number', function (): void {
    Queue::fake();
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0ABC')->create(['provider' => Provider::WaGateway]));
    $key = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create())->session_key;

    gatewayHook('message.status', $key, ['id' => '3EB0ABC', 'status' => 'failed'])->assertOk();

    expect($this->inTenant(fn () => $message->fresh()?->status))->toBe(MessageStatus::FellBack);
    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id, 'channel' => 'whatsapp', 'error_code' => null]);
});

test('a receipt for a platform message with the same id is ignored', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0ABC')->create(['provider' => Provider::Hypersender]));
    $key = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create())->session_key;

    gatewayHook('message.status', $key, ['id' => '3EB0ABC', 'status' => 'delivered'])->assertOk();

    $this->assertDatabaseHas('outbound_messages', ['id' => $message->id, 'status' => 'sent']);
});

test('a restarted gateway queues a reconcile', function (): void {
    Queue::fake();

    gatewayHook('gateway.started', null)->assertOk();

    Queue::assertPushedOn('messaging', ReconcileWaGateway::class);
});

test('events about sessions or messages we do not know are acknowledged and dropped', function (string $event, array $data): void {
    gatewayHook($event, '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b', $data)->assertOk();

    $this->assertDatabaseCount('whatsapp_connections', 0);
})->with([
    'open of an unknown session' => ['session.open', ['phone' => '+201001234567']],
    'receipt of an unknown message' => ['message.status', ['id' => 'NOPE', 'status' => 'delivered']],
]);

test('tasks/12 lesson 5 a webhook without a valid signature is refused and changes nothing', function (?string $secret, int $skew): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());

    gatewayHook('session.open', $connection->session_key, ['phone' => '+201001234567'], $secret, now()->getTimestamp() + $skew)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    expect(connectionRow($connection)->status)->toBe(ConnectionStatus::Pairing);
})->with([
    'wrong secret' => ['nope', 0],
    'no signature' => [null, 0],
    'stale timestamp' => ['gateway-secret', -301],
    'future timestamp' => ['gateway-secret', 301],
]);

test('an invalid body is refused', function (string $event, ?string $key, array $data, string $field): void {
    gatewayHook($event, $key, $data)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown event' => ['session.weird', '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b', [], 'event'],
    'session event without a key' => ['session.open', null, [], 'session_key'],
    'key that is not a uuid' => ['session.open', 'tenant-1', [], 'session_key'],
    'phone that is not e.164' => ['session.open', '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b', ['phone' => '01001234567'], 'data.phone'],
    'receipt without an id' => ['message.status', '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b', ['status' => 'delivered'], 'data.id'],
    'receipt with an unknown status' => ['message.status', '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b', ['id' => 'x', 'status' => 'seen'], 'data.status'],
]);

test('the webhook only exists on the hooks host', function (): void {
    $this->postJson('http://alnoor.gomal.test/api/webhooks/wa-gateway', [])->assertNotFound();
});
