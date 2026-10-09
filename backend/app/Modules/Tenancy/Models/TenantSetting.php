<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TenantSetting extends Model
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $fillable = [
        'logo_path', 'favicon_path', 'primary_color', 'secondary_color',
        'phone', 'whatsapp', 'address', 'description', 'working_hours',
        'currency', 'mov_enabled', 'mov_amount',
        'require_login_for_prices', 'require_customer_approval', 'stale_pending_hours',
    ];

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

    /** Fields the merchant may edit through the dashboard. */
    public const EDITABLE = [
        'primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description',
        'working_hours', 'mov_enabled', 'mov_amount',
        'require_login_for_prices', 'require_customer_approval', 'stale_pending_hours',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    /** Shape returned to both storefront and dashboard. */
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
}
