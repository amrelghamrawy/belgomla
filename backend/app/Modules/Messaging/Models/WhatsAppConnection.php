<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\WhatsAppConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * D70, tasks/12: the store's own WhatsApp number, linked by QR through wa-gateway. One row per tenant;
 * `session_key` is the only id the gateway sees and is replaced on every new pairing.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $session_key
 * @property ConnectionStatus $status
 * @property string|null $phone
 * @property string|null $disconnect_reason
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WhatsAppConnection extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WhatsAppConnectionFactory> */
    use HasFactory;

    protected $table = 'whatsapp_connections';

    protected static function newFactory(): WhatsAppConnectionFactory
    {
        return WhatsAppConnectionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'connected_at' => 'datetime',
            'last_event_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
