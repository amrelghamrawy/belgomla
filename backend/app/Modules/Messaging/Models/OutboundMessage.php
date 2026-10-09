<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tech §13, BR-CU07: one WhatsApp or SMS message sent for a tenant. `body` is kept encrypted only until the
 * message is final, because a late WhatsApp failure still needs the text for the SMS fallback.
 * Created by Messaging's Messenger and changed only by its RecordDeliveryStatus action.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property string $to_phone
 * @property MessagePurpose $purpose
 * @property Channel $channel
 * @property Provider $provider
 * @property string|null $provider_request_id
 * @property string|null $provider_message_id
 * @property MessageStatus $status
 * @property string|null $error_code
 * @property string|null $body
 * @property int|null $fallback_of_id
 * @property string|null $cost
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OutboundMessage|null $fallbackOf
 * @property-read OutboundMessage|null $fallback
 */
final class OutboundMessage extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    protected $hidden = ['body'];

    protected static function newFactory(): OutboundMessageFactory
    {
        return OutboundMessageFactory::new();
    }

    /**
     * The failed WhatsApp message this SMS replaces.
     *
     * @return BelongsTo<OutboundMessage, $this>
     */
    public function fallbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_of_id');
    }

    /**
     * The SMS sent because this WhatsApp message failed.
     *
     * @return HasOne<OutboundMessage, $this>
     */
    public function fallback(): HasOne
    {
        return $this->hasOne(self::class, 'fallback_of_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => MessagePurpose::class,
            'channel' => Channel::class,
            'provider' => Provider::class,
            'status' => MessageStatus::class,
            'body' => 'encrypted',
            'cost' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
