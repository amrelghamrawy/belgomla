<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Backs `GET /up` (tasks/11): Nginx, the deploy assertion and monitor.sh call it, so it must fail when the
 * app cannot serve a real request. Any exception here turns /up into a 500.
 */
final readonly class HealthProbe
{
    public function check(): void
    {
        DB::connection()->select('select 1');

        if ($this->usesRedis()) {
            Redis::connection()->ping();
        }
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver')], true);
    }
}
