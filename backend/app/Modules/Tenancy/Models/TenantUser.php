<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantUser extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<TenantUserFactory> */
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'role', 'status', 'last_login_at'];

    protected $attributes = [
        'role' => self::ROLE_OWNER,
        'status' => self::STATUS_ACTIVE,
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): TenantUserFactory
    {
        return TenantUserFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
