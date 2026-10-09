<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property TenantUserRole $role
 * @property TenantUserStatus $status
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $remember_token
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TenantUser extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<TenantUserFactory> */
    use HasFactory;

    protected $attributes = [
        'role' => 'owner',
        'status' => 'active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): TenantUserFactory
    {
        return TenantUserFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === TenantUserStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => TenantUserRole::class,
            'status' => TenantUserStatus::class,
            'last_login_at' => 'datetime',
        ];
    }
}
