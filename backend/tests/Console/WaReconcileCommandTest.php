<?php

declare(strict_types=1);

use App\Modules\Messaging\Jobs\ReconcileWaGateway;
use App\Modules\Messaging\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| messaging:wa-reconcile (D70, tasks/12 lesson 6): Laravel decides which sessions wa-gateway runs.
*/

beforeEach(function (): void {
    useWaGateway();
    Http::preventStrayRequests();
});

/**
 * @param  list<string>  $live
 * @param  list<string>  $stored
 */
function fakeGatewaySessions(array $live, array $stored, int $startStatus = 200): void
{
    Http::fake(fn (Request $request) => match (true) {
        $request->url() === 'http://127.0.0.1:3100/sessions' => Http::response(['live' => $live, 'stored' => $stored]),
        $request->method() === 'POST' => Http::response(['state' => 'connecting', 'qr' => null, 'phone' => null], $startStatus),
        default => Http::response(['logged_out' => true]),
    });
}

test('connected numbers the gateway does not run are started again', function (): void {
    $connected = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    fakeGatewaySessions([], [$connected->session_key]);

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('started 1, removed 0, failed 0')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), $connected->session_key));
});

test('sessions that are neither connected nor pairing here are removed', function (): void {
    $unlinked = $this->inTenant(fn () => WhatsAppConnection::factory()->disconnected()->create());
    $pairing = $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->pairing()->create());
    $orphan = '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b';
    fakeGatewaySessions([$pairing->session_key, $unlinked->session_key], [$orphan]);

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('started 0, removed 2, failed 0')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), $orphan));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), $pairing->session_key));
});

test('a live connected session is left alone', function (): void {
    $connected = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    fakeGatewaySessions([$connected->session_key], [$connected->session_key]);

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('started 0, removed 0, failed 0')->assertSuccessful();

    Http::assertSentCount(1);
});

test('a session the full gateway cannot start is counted and fails the run', function (): void {
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    fakeGatewaySessions([], [], 503);

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('failed 1')->assertFailed();
});

test('an unreachable gateway fails the run', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('wa-gateway is not reachable.')->assertFailed();
});

test('nothing is called while the gateway is disabled', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);
    Http::fake();

    $this->artisan('messaging:wa-reconcile')->expectsOutputToContain('wa-gateway is disabled.')->assertSuccessful();
    Http::assertNothingSent();
});

test('the reconcile job runs the same reconcile on the messaging queue', function (): void {
    $connected = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    fakeGatewaySessions([], []);
    $job = new ReconcileWaGateway;

    app()->call($job->handle(...));

    expect([$job->queue, $job->tries, $job->backoff()])->toBe(['messaging', 3, [10, 30]]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), $connected->session_key));
});

test('the reconcile job does nothing while the gateway is disabled', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);
    Http::fake();

    app()->call((new ReconcileWaGateway)->handle(...));

    Http::assertNothingSent();
});
