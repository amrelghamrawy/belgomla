<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Models\SmsPhoneHeartbeat;
use Carbon\CarbonImmutable;

/** D49: when any platform SMS phone was last heard from, or null if none ever was. */
final readonly class LastPhoneHeartbeatQuery
{
    public function get(): ?CarbonImmutable
    {
        return SmsPhoneHeartbeat::query()->orderByDesc('last_seen_at')->first()?->last_seen_at;
    }
}
