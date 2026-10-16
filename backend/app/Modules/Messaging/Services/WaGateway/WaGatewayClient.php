<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\WaGateway;

use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for wa-gateway (D70, `wa-gateway/README.md`): 127.0.0.1 only, `Authorization: Bearer <token>`.
 * Session calls throw WaGatewayUnavailable when the gateway is down or full; sends go through `http()` in
 * WaGatewayDriver, which turns every answer into a DriverResult instead.
 *
 * @phpstan-type SessionView array{state: string, qr: string|null, phone: string|null}
 */
final readonly class WaGatewayClient
{
    public function http(): PendingRequest
    {
        return Http::baseUrl(mb_rtrim(config()->string('messaging.wa_gateway.base_url'), '/'))
            ->withToken(config()->string('messaging.wa_gateway.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('messaging.wa_gateway.timeout'));
    }

    /**
     * Starts (or reports) the session of a key.
     *
     * @return SessionView
     */
    public function start(string $key): array
    {
        $response = $this->call(fn (): Response => $this->http()->post("sessions/{$key}"));

        if ($response->status() === 503) {
            throw WaGatewayUnavailable::full();
        }

        return $this->view($this->ensureOk($response));
    }

    /**
     * The live session of a key, or null when the gateway does not run it.
     *
     * @return SessionView|null
     */
    public function session(string $key): ?array
    {
        $response = $this->call(fn (): Response => $this->http()->get("sessions/{$key}"));

        return $response->notFound() ? null : $this->view($this->ensureOk($response));
    }

    /** Logs the session out of the phone and deletes it; true when the phone was told (tasks/12 lesson 1). */
    public function remove(string $key): bool
    {
        $response = $this->ensureOk($this->call(fn (): Response => $this->http()->delete("sessions/{$key}")));

        return $response->json('logged_out') === true;
    }

    /**
     * Session keys the gateway runs (`live`) and holds credentials for (`stored`).
     *
     * @return array{live: list<string>, stored: list<string>}
     */
    public function sessions(): array
    {
        $response = $this->ensureOk($this->call(fn (): Response => $this->http()->get('sessions')));

        return ['live' => $this->keys($response->json('live')), 'stored' => $this->keys($response->json('stored'))];
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw WaGatewayUnavailable::down();
        }
    }

    private function ensureOk(Response $response): Response
    {
        if (! $response->successful()) {
            throw WaGatewayUnavailable::down();
        }

        return $response;
    }

    /**
     * @return SessionView
     */
    private function view(Response $response): array
    {
        $state = $response->json('state');
        $qr = $response->json('qr');
        $phone = $response->json('phone');

        return [
            'state' => is_string($state) ? $state : 'closed',
            'qr' => is_string($qr) ? $qr : null,
            'phone' => is_string($phone) ? $phone : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function keys(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
