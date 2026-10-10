<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

/*
| GET /up (tasks/11): Nginx, deploy.sh and monitor.sh rely on it failing when the app cannot serve a request.
*/

/** Points the app at Redis on the given port and forgets any connection already made. */
function useRedisOnPort(int $port): void
{
    config([
        'app.debug' => false, // with debug on, Laravel rethrows the failure instead of rendering the 500 page
        'cache.default' => 'redis',
        'database.redis.client' => 'predis',
        'database.redis.default.port' => $port,
    ]);

    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();
}

test('/up answers 200 when the database is reachable', function (): void {
    $this->get('/up')->assertOk();
});

test('/up answers 200 when redis is used and reachable', function (): void {
    useRedisOnPort(6379);

    $this->get('/up')->assertOk();
});

test('/up answers 500 when redis is used and unreachable', function (): void {
    useRedisOnPort(1);

    $this->get('/up')->assertInternalServerError();
});

test('/up answers on the hooks host and on a tenant host', function (string $host): void {
    $this->get("http://{$host}/up")->assertOk();
})->with(['hooks.gomal.test', 'alnoor.gomal.test']);
