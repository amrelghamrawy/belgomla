<?php

declare(strict_types=1);

use App\Modules\Messaging\Actions\RecordConnectionEvent;
use App\Modules\Messaging\Data\GatewayEventData;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
});

function recordEvent(WhatsAppConnection $connection, GatewayEventData $event): WhatsAppConnection
{
    return test()->inTenant(fn () => app(RecordConnectionEvent::class)->handle($connection, $event));
}

function opened(WhatsAppConnection $connection, ?string $phone = '+201001234567'): GatewayEventData
{
    return new GatewayEventData(GatewayEventData::OPEN, $connection->session_key, phone: $phone);
}

function closed(WhatsAppConnection $connection, string $reason, bool $fatal): GatewayEventData
{
    return new GatewayEventData(GatewayEventData::CLOSED, $connection->session_key, reason: $reason, fatal: $fatal);
}

test('an open session connects the number and stamps the time', function (): void {
    $this->freezeSecond();
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());

    $recorded = recordEvent($connection, opened($connection));

    expect([$recorded->status, $recorded->phone, $recorded->disconnect_reason])->toBe([ConnectionStatus::Connected, '+201001234567', null])
        ->and($recorded->connected_at?->equalTo(now()))->toBeTrue()
        ->and($recorded->last_event_at?->equalTo(now()))->toBeTrue();
});

test('a reconnect of a connected number keeps the time it was first connected', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create(['connected_at' => now()->subDay()]));
    $this->travel(1)->hours();

    $recorded = recordEvent($connection, opened($connection));

    expect($recorded->connected_at?->equalTo($connection->connected_at))->toBeTrue();
});

test('tasks/12 lesson 2 a phone connected on another store is refused and its session dropped', function (): void {
    $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(["127.0.0.1:3100/sessions/{$connection->session_key}" => Http::response(['logged_out' => true])]);

    $recorded = recordEvent($connection, opened($connection));

    expect([$recorded->status, $recorded->disconnect_reason])->toBe([ConnectionStatus::Error, 'phone_in_use']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a refused phone stays refused when the gateway cannot drop the session', function (): void {
    $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201001234567')->create());
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(recordEvent($connection, opened($connection))->status)->toBe(ConnectionStatus::Error);
});

test('tasks/12 lesson 4 a fatal close logs the number out with the reason', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $recorded = recordEvent($connection, closed($connection, 'logged_out', true));

    expect([$recorded->status, $recorded->disconnect_reason, $recorded->phone])->toBe([ConnectionStatus::LoggedOut, 'logged_out', '+201001234567']);
});

test('an expired qr disconnects the pairing', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->pairing()->create());

    $recorded = recordEvent($connection, closed($connection, 'qr_expired', false));

    expect([$recorded->status, $recorded->disconnect_reason])->toBe([ConnectionStatus::Disconnected, 'qr_expired']);
});

test('exhausted reconnects keep the number connected for the reconcile to restart', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $recorded = recordEvent($connection, closed($connection, 'reconnect_exhausted', false));

    expect([$recorded->status, $recorded->disconnect_reason])->toBe([ConnectionStatus::Connected, 'reconnect_exhausted']);
});

test('late events for an unlinked or refused number change nothing', function (string $state): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->{$state}()->create());

    $recorded = recordEvent($connection, opened($connection));

    expect($recorded->status)->toBe($connection->status)
        ->and($recorded->updated_at->equalTo($connection->updated_at))->toBeTrue();
})->with(['disconnected', 'error']);
