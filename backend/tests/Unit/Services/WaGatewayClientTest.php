<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\StoreWhatsAppStatus;
use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Http\Resources\StoreWhatsAppResource;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const SESSION = '0b4d2f6e-1c3a-4e5b-9d7f-2a8c6e4b1d3f';

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
});

test('a started session is read from the gateway answer', function (): void {
    Http::fake(['127.0.0.1:3100/sessions/'.SESSION => Http::response(['state' => 'pairing', 'qr' => 'data:x', 'phone' => null])]);

    expect(app(WaGatewayClient::class)->start(SESSION))->toBe(['state' => 'pairing', 'qr' => 'data:x', 'phone' => null]);
});

test('a malformed session answer is read as closed', function (): void {
    Http::fake(['*' => Http::response(['state' => 5, 'qr' => [], 'phone' => false])]);

    expect(app(WaGatewayClient::class)->session(SESSION))->toBe(['state' => 'closed', 'qr' => null, 'phone' => null]);
});

test('a session the gateway does not run is null', function (): void {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    expect(app(WaGatewayClient::class)->session(SESSION))->toBeNull();
});

test('removing reports whether the phone was told', function (bool $loggedOut): void {
    Http::fake(['*' => Http::response(['logged_out' => $loggedOut])]);

    expect(app(WaGatewayClient::class)->remove(SESSION))->toBe($loggedOut);
})->with([true, false]);

test('the session list keeps only string keys', function (): void {
    Http::fake(['*' => Http::response(['live' => [SESSION, 7], 'stored' => 'nope'])]);

    expect(app(WaGatewayClient::class)->sessions())->toBe(['live' => [SESSION], 'stored' => []]);
});

test('a gateway that is down or refuses is unavailable', function (Closure $response, WaGatewayUnavailable $expected): void {
    Http::fake(['*' => $response]);

    expect(fn () => app(WaGatewayClient::class)->start(SESSION))->toThrow($expected);
})->with([
    'connection refused' => [fn () => throw new ConnectionException('refused'), WaGatewayUnavailable::down()],
    'unauthenticated' => [fn () => Http::response(['error' => 'unauthenticated'], 401), WaGatewayUnavailable::down()],
    'server error' => [fn () => Http::response(['error' => 'server_error'], 500), WaGatewayUnavailable::down()],
    'full' => [fn () => Http::response(['error' => 'capacity'], 503), WaGatewayUnavailable::full()],
]);

test('the store whatsapp resource lists its keys, with logged_out_on_phone only after an unlink', function (): void {
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $shown = rendered(new StoreWhatsAppResource(new StoreWhatsAppStatus(true, $connection)), dashboardRequest('/api/dashboard/settings/whatsapp'));
    $unlinked = rendered(new StoreWhatsAppResource(new StoreWhatsAppStatus(true, $connection, loggedOutOnPhone: false)), dashboardRequest('/api/dashboard/settings/whatsapp'));

    expect(array_keys($shown))->toBe(['enabled', 'status', 'status_label', 'phone', 'connected_at', 'disconnect_reason', 'qr'])
        ->and($unlinked['logged_out_on_phone'])->toBeFalse();
});
