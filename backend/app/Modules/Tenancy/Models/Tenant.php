<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\Vertical;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $subdomain
 * @property string $name
 * @property TenantStatus $status
 * @property Vertical $vertical
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read TenantSetting|null $settings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TenantUser> $users
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

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

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    public function host(): string
    {
        return $this->subdomain.'.'.config()->string('tenancy.base_domain');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'vertical' => Vertical::class,
        ];
    }
}
