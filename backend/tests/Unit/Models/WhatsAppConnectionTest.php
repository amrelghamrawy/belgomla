<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\ConnectedStoreNumberQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create()->fresh());

    expect(array_keys($connection?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'session_key', 'status', 'phone', 'disconnect_reason', 'connected_at', 'last_event_at', 'created_at', 'updated_at',
    ]);
});

test('casts: status enum and immutable timestamps', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create()->fresh());

    expect($connection?->status)->toBe(ConnectionStatus::Connected)
        ->and($connection?->connected_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($connection?->last_event_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($connection?->session_key)->toBeUuid();
});

test('BR-T03 a connection created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => WhatsAppConnection::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a connection without a tenant in context throws', function (): void {
    WhatsAppConnection::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 connection queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->create());
    app(TenantContext::class)->clear();

    expect(WhatsAppConnection::query()->count())->toBe(0);
});

test('D70 a store has one number', function (): void {
    expect(fn () => $this->inTenant(fn () => WhatsAppConnection::factory()->count(2)->create()))
        ->toThrow(QueryException::class, 'whatsapp_connections_tenant_id_unique');
});

test('tasks/12 lesson 2 a number is connected on one store only', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());

    expect(fn () => $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201001234567')->create()))
        ->toThrow(QueryException::class, 'whatsapp_connections_connected_phone');
});

test('a number that dropped on one store can be connected on another', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->loggedOut()->create(['phone' => '+201001234567']));

    $other = $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());

    expect($other->phone)->toBe('+201001234567');
});

test('the database refuses an unknown status', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->create());

    expect(fn () => DB::table('whatsapp_connections')->where('id', $connection->id)->update(['status' => 'banned']))
        ->toThrow(QueryException::class, 'whatsapp_connections_status_known');
});

test('D70 the connected store number query finds only a connected number of this tenant', function (): void {
    useWaGateway();
    $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201009999999')->create());
    $mine = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    expect($this->inTenant(fn () => app(ConnectedStoreNumberQuery::class)->get())?->is($mine))->toBeTrue();
});

test('D70 the connected store number query is empty while the number is not connected', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());

    expect($this->inTenant(fn () => app(ConnectedStoreNumberQuery::class)->get()))->toBeNull();
});

test('D70 the connected store number query is empty when the gateway is disabled', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    config(['messaging.wa_gateway.enabled' => false]);

    expect($this->inTenant(fn () => app(ConnectedStoreNumberQuery::class)->get()))->toBeNull();
});
