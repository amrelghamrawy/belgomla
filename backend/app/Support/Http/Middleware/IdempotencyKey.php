<?php

declare(strict_types=1);

namespace App\Support\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honors the `Idempotency-Key` header (08-api-contract.md §0, D55): the first successful (2xx) answer to a key is
 * stored for 24 hours and replayed, with `Idempotent-Replayed: true`, to every retry, so a double-tapped
 * checkout creates one order. The key is scoped to the tenant host, the signed-in user (guard + id) and the
 * route, so one user's key never answers another's request. While the first request runs, a retry gets
 * `409 idempotency_in_progress`; the same key with a different body gets `422 idempotency_key_reused`.
 * Failures (4xx/5xx) are not stored, so a corrected retry with the same key runs. Without the header the
 * request runs normally. Must run after authentication (alias `idempotent`, listed after `auth:*`).
 */
final readonly class IdempotencyKey
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    public function __construct(private CacheFactory $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            /** @var Response */
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_\-:.]{1,255}$/', $key) !== 1) {
            throw ApiException::unprocessable('invalid_idempotency_key', 'مفتاح التكرار غير صالح');
        }

        $store = $this->store();
        $scope = $this->scope($request, $key);
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $stored = $store->get($scope);

        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $backend = $store->getStore();
        $lock = $backend instanceof LockProvider
            ? $backend->lock($scope.':lock', config()->integer('idempotency.lock_seconds'))
            : null;

        if ($lock !== null && ! $lock->get()) {
            throw ApiException::conflict('idempotency_in_progress', 'الطلب نفسه قيد التنفيذ، انتظر لحظة');
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response->isSuccessful()) {
                $store->put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                ], config()->integer('idempotency.ttl_seconds'));
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    private function store(): Repository
    {
        $name = config('idempotency.store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }

    private function scope(Request $request, string $key): string
    {
        return 'idempotency:'.hash('sha256', implode('|', [
            $request->getHost(),
            $this->user($request),
            $request->route()?->getName() ?? $request->path(),
            $key,
        ]));
    }

    /** The first guard with a signed-in user, as `guard:id`; guests share the `guest` scope. */
    private function user(Request $request): string
    {
        foreach (array_keys(config()->array('auth.guards')) as $guard) {
            $id = $request->user((string) $guard)?->getAuthIdentifier();

            if (is_int($id) || is_string($id)) {
                return $guard.':'.$id;
            }
        }

        return 'guest';
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    private function replay(array $stored, string $fingerprint): JsonResponse
    {
        if (($stored['fingerprint'] ?? null) !== $fingerprint) {
            throw ApiException::unprocessable('idempotency_key_reused', 'استُخدم مفتاح التكرار نفسه لطلب مختلف');
        }

        $status = is_int($stored['status'] ?? null) ? $stored['status'] : 200;
        $body = is_string($stored['body'] ?? null) ? $stored['body'] : '{}';

        return JsonResponse::fromJsonString($body, $status, [self::REPLAYED_HEADER => 'true']);
    }
}
