<?php

namespace App\Modules\Tenancy\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const VERTICALS = ['school-books', 'stationery', 'hardware', 'none'];

    protected $fillable = ['subdomain', 'name', 'status', 'vertical'];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
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
        return $this->subdomain.'.'.config('tenancy.base_domain');
    }
}
