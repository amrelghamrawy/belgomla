<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $subdomain
 * @property string $name
 * @property string $status
 * @property string $vertical
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantSetting|null $settings
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** @var list<string> */
    public const VERTICALS = ['school-books', 'stationery', 'hardware', 'none'];

    protected $fillable = ['subdomain', 'name', 'status', 'vertical'];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    /**
     * @return HasOne<TenantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /**
     * @return HasMany<TenantUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /** The settings row always exists after provisioning; create it defensively otherwise. */
    public function settingsRow(): TenantSetting
    {
        return $this->settings ?? $this->settings()->create([]);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function host(): string
    {
        return $this->subdomain.'.'.config()->string('tenancy.base_domain');
    }
}
