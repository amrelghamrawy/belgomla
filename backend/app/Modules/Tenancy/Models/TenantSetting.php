<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $tenant_id
 * @property string|null $logo_path
 * @property string|null $favicon_path
 * @property string $primary_color
 * @property string $secondary_color
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $address
 * @property string|null $description
 * @property array<int, array{day: int, from: string, to: string}>|null $working_hours
 * @property string $currency
 * @property bool $mov_enabled
 * @property string|null $mov_amount
 * @property bool $require_login_for_prices
 * @property bool $require_customer_approval
 * @property int $stale_pending_hours
 * @property string|null $invitation_message
 * @property int|null $default_payment_terms_days applied to new customers (BR-C06)
 * @property string|null $default_credit_limit applied to new customers (BR-C05)
 * @property list<string> $order_notify_statuses the order statuses customers are told about (BR-N03)
 * @property array<string, string|null>|null $order_status_messages status => the store's own wording (null = default)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Tenant $tenant
 */
final class TenantSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path === null ? null : Storage::disk('public')->url($this->favicon_path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'working_hours' => 'array',
            'mov_enabled' => 'boolean',
            'mov_amount' => 'decimal:2',
            'require_login_for_prices' => 'boolean',
            'require_customer_approval' => 'boolean',
            'stale_pending_hours' => 'integer',
            'default_payment_terms_days' => 'integer',
            'default_credit_limit' => 'decimal:2',
            'order_notify_statuses' => 'array',
            'order_status_messages' => 'array',
        ];
    }
}
