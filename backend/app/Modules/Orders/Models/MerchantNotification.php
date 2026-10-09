<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\MerchantNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-N02: an in-app dashboard notification, a row of our own `notifications` table (07-domain-model.md §9),
 * not Laravel's notification table; named apart from Laravel's `Notification`. A null `tenant_user_id` is
 * addressed to every owner of the tenant, who share its `read_at` (D57).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $tenant_user_id
 * @property NotificationType $type
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MerchantNotification extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MerchantNotificationFactory> */
    use HasFactory;

    protected $table = 'notifications';

    protected static function newFactory(): MerchantNotificationFactory
    {
        return MerchantNotificationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_user_id' => 'integer',
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
