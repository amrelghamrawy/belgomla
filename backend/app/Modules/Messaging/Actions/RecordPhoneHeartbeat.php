<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\SmsPhoneHeartbeat;

/**
 * D49: stores that the platform SMS phone is alive. The time is ours, not the payload's, so the monitor's
 * "2 hours without a heartbeat" check never depends on the provider's clock or timezone.
 */
final readonly class RecordPhoneHeartbeat
{
    public function handle(string $phoneId): void
    {
        SmsPhoneHeartbeat::query()->updateOrCreate(['phone_id' => $phoneId], ['last_seen_at' => now()]);
    }
}
