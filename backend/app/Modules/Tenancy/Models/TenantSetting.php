<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantSetting extends Model
{
    /** Fields the merchant may edit through the dashboard. */
    public const EDITABLE = [
        'primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description',
        'working_hours', 'mov_enabled', 'mov_amount',
        'require_login_for_prices', 'require_customer_approval', 'stale_pending_hours',
    ];

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $fillable = [
        'logo_path', 'favicon_path', 'primary_color', 'secondary_color',
        'phone', 'whatsapp', 'address', 'description', 'working_hours',
        'currency', 'mov_enabled', 'mov_amount',
        'require_login_for_prices', 'require_customer_approval', 'stale_pending_hours',
    ];

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
     * Shape returned to both storefront and dashboard.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(Tenant $tenant): array
    {
        return [
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'logo_url' => $this->logoUrl(),
            'favicon_url' => $this->faviconUrl(),
            'colors' => ['primary' => $this->primary_color, 'secondary' => $this->secondary_color],
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => $this->address,
            'description' => $this->description,
            'working_hours' => $this->working_hours ?? [],
            'currency' => $this->currency,
            'require_login_for_prices' => $this->require_login_for_prices,
            'require_customer_approval' => $this->require_customer_approval,
            'mov' => ['enabled' => $this->mov_enabled, 'amount' => $this->mov_amount],
        ];
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
        ];
    }
}
