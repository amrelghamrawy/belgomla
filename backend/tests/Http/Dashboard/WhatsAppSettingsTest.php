<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| GET|POST|DELETE /api/dashboard/settings/whatsapp (D70, tasks/12): the store's own WhatsApp number.
*/

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
    $this->owner = $this->actingAsOwner();
});

function storeConnection(): ?WhatsAppConnection
{
    return test()->inTenant(fn (): ?WhatsAppConnection => WhatsAppConnection::query()->first());
}

const QR = 'data:image/png;base64,iVBORw0KGgo=';

test('a store that never linked a number is shown as disconnected', function (): void {
    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertExactJson(['data' => [
            'enabled' => true,
            'status' => 'disconnected',
            'status_label' => 'غير مربوط',
            'phone' => null,
            'connected_at' => null,
            'disconnect_reason' => null,
            'qr' => null,
        ]]);
});

test('the response has exactly the documented keys', function (): void {
    $data = $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')->assertOk()->json('data');

    expectDocumentedShape($data, 'StoreWhatsApp');
});

test('a disabled gateway hides the section without calling it', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);
    Http::fake();

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.status', 'disconnected');
    Http::assertNothingSent();
});

test('a connected number is shown with its phone without calling the gateway', function (): void {
    $this->freezeTime();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());
    Http::fake();

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.phone', '+201001234567')
        ->assertJsonPath('data.connected_at', now()->toIso8601ZuluString())
        ->assertJsonPath('data.qr', null);
    Http::assertNothingSent();
});

test('D70 linking starts a gateway session and moves the store to pairing, audited', function (): void {
    Http::fake(['127.0.0.1:3100/sessions/*' => Http::response(['state' => 'connecting', 'qr' => null, 'phone' => null])]);

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'pairing')
        ->assertJsonPath('data.qr', null);

    $connection = storeConnection();
    expect($connection?->status)->toBe(ConnectionStatus::Pairing)
        ->and($connection?->session_key)->toBeUuid();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "http://127.0.0.1:3100/sessions/{$connection?->session_key}"
        && $request->hasHeader('Authorization', 'Bearer gateway-token'));
    $log = $this->inTenant(fn () => AuditLog::query()->sole());
    expect([$log->action, $log->actor_id, $log->old_values, $log->new_values])
        ->toEqual(['whatsapp.pairing_started', $this->owner->id, null, ['status' => 'pairing', 'phone' => null]]);
});

test('the qr shows while the gateway waits for the scan', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(["127.0.0.1:3100/sessions/{$connection->session_key}" => Http::response(['state' => 'pairing', 'qr' => QR, 'phone' => null])]);

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'pairing')
        ->assertJsonPath('data.qr', QR);
});

test('a scan the gateway reports open is recorded by the poll, without waiting for the webhook', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(['127.0.0.1:3100/*' => Http::response(['state' => 'open', 'qr' => null, 'phone' => '+201001234567'])]);

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.phone', '+201001234567');

    expect(storeConnection()?->status)->toBe(ConnectionStatus::Connected);
});

test('tasks/12 lesson 3 a pairing the gateway no longer runs is shown as an expired qr', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(['127.0.0.1:3100/*' => Http::response(['error' => 'not_found'], 404)]);

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'disconnected')
        ->assertJsonPath('data.disconnect_reason', 'qr_expired');
});

test('a gateway that is down during a pairing leaves the status as it is', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'pairing')
        ->assertJsonPath('data.qr', null);
});

test('linking again while pairing keeps the same session key', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(['127.0.0.1:3100/*' => Http::response(['state' => 'pairing', 'qr' => QR, 'phone' => null])]);

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')->assertOk()->assertJsonPath('data.qr', QR);

    expect(storeConnection()?->session_key)->toBe($connection->session_key);
});

test('linking after a dropped number starts over with a new session key', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->loggedOut()->create());
    Http::fake(['127.0.0.1:3100/*' => Http::response(['state' => 'connecting', 'qr' => null, 'phone' => null])]);

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')->assertOk()->assertJsonPath('data.status', 'pairing');

    expect(storeConnection()?->session_key)->not->toBe($connection->session_key)
        ->and(storeConnection()?->phone)->toBeNull()
        ->and(storeConnection()?->disconnect_reason)->toBeNull();
    $this->assertDatabaseCount('whatsapp_connections', 1);
});

test('D70 a connected number must be unlinked before linking another', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    Http::fake();

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'whatsapp_already_connected')
        ->assertJsonPath('error.message', 'رقم واتساب مربوط بالفعل، افصله أولًا لربط رقم آخر');
    Http::assertNothingSent();
});

test('a gateway that is down refuses the link and saves nothing', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'wa_gateway_unavailable')
        ->assertJsonPath('error.message', 'خدمة ربط واتساب غير متاحة الآن، حاول بعد قليل');
    $this->assertDatabaseCount('whatsapp_connections', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('tasks/12 lesson 7 a full gateway refuses the link with its own code', function (): void {
    Http::fake(['127.0.0.1:3100/*' => Http::response(['error' => 'capacity'], 503)]);

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'wa_gateway_capacity')
        ->assertJsonPath('error.message', 'لا يمكن ربط أرقام جديدة الآن، تواصل مع الدعم');
    $this->assertDatabaseCount('whatsapp_connections', 0);
});

test('linking is not found when the feature is disabled', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);
    Http::fake();

    $this->tenantJson('POST', '/api/dashboard/settings/whatsapp')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    Http::assertNothingSent();
});

test('tasks/12 lesson 1 unlinking logs the device out of the phone, then disconnects, audited', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());
    Http::fake(["127.0.0.1:3100/sessions/{$connection->session_key}" => Http::response(['logged_out' => true])]);

    $this->tenantJson('DELETE', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'disconnected')
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.disconnect_reason', 'unlinked')
        ->assertJsonPath('data.logged_out_on_phone', true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    $log = $this->inTenant(fn () => AuditLog::query()->sole());
    expect([$log->action, $log->old_values, $log->new_values])
        ->toEqual(['whatsapp.unlinked', ['status' => 'connected', 'phone' => '+201001234567'], ['status' => 'disconnected', 'phone' => null]]);
});

test('unlinking while the gateway is down still disconnects and says the phone was not told', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->tenantJson('DELETE', '/api/dashboard/settings/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.status', 'disconnected')
        ->assertJsonPath('data.logged_out_on_phone', false);
});

test('unlinking a number that is already disconnected changes nothing', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->disconnected()->create());
    Http::fake();

    $this->tenantJson('DELETE', '/api/dashboard/settings/whatsapp')->assertOk()->assertJsonPath('data.logged_out_on_phone', false);
    Http::assertNothingSent();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('unlinking is not found when the store never linked a number', function (): void {
    Http::fake();

    $this->tenantJson('DELETE', '/api/dashboard/settings/whatsapp')->assertNotFound();
});

test('BR-T04 a store sees and unlinks only its own number', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->asTenant($other, fn () => WhatsAppConnection::factory()->connected('+201009999999')->create());
    Http::fake();

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp')->assertOk()->assertJsonPath('data.status', 'disconnected');
    $this->tenantJson('DELETE', '/api/dashboard/settings/whatsapp')->assertNotFound();

    $this->assertDatabaseHas('whatsapp_connections', ['id' => $theirs->id, 'status' => 'connected', 'phone' => '+201009999999']);
});

test('a session of one store is refused on another store host', function (): void {
    $other = $this->otherTenant();

    $this->tenantJson('GET', '/api/dashboard/settings/whatsapp', tenant: $other)->assertUnauthorized();
});

test('guests are refused', function (string $method): void {
    auth('tenant_user')->logout();

    $this->tenantJson($method, '/api/dashboard/settings/whatsapp')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with(['GET', 'POST', 'DELETE']);
