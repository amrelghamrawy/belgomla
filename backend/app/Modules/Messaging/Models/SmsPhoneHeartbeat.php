<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SmsPhoneHeartbeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * D49: the last heartbeat the Hypersender SMS app on the platform phone sent. Platform-level, not tenant-owned.
 * Written by RecordPhoneHeartbeat, read by the monitor (tasks/11) through LastPhoneHeartbeatQuery.
 *
 * @property int $id
 * @property string $phone_id
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SmsPhoneHeartbeat extends Model
{
    /** @use HasFactory<SmsPhoneHeartbeatFactory> */
    use HasFactory;

    protected static function newFactory(): SmsPhoneHeartbeatFactory
    {
        return SmsPhoneHeartbeatFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
