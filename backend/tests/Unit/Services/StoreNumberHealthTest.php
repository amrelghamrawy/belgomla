<?php

declare(strict_types=1);

use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\StoreNumberHealth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| D70 (tasks/12 slice 6): what platform:monitor learns about wa-gateway and the connected store numbers.
*/

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
});

test('a disabled gateway reports nothing', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);

    expect(app(StoreNumberHealth::class)->get())->toBeNull();

    Http::assertNothingSent();
});

test('the open sessions are compared with connected numbers of every tenant', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected('+201001111111')->create());
    $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201002222222')->create());
    $this->asTenant($this->otherTenant('third'), fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(['127.0.0.1:3100/health' => Http::response(['ok' => true, 'sessions' => 3, 'open' => 1])]);

    expect(app(StoreNumberHealth::class)->get())->toBe(['reachable' => true, 'open' => 1, 'connected' => 2]);
});

test('a gateway that does not answer is unreachable', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    expect(app(StoreNumberHealth::class)->get())->toBe(['reachable' => false, 'open' => 0, 'connected' => 1]);
});
