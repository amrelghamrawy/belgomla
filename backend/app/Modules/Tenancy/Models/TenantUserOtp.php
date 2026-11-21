<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\TenantUserOtpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D112, BR-T10: a password reset code sent to a dashboard user's phone. Only the hash is stored; valid 10 minutes,
 * 5 attempts, single use, like a customer code (BR-CU07).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $tenant_user_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TenantUserOtp extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantUserOtpFactory> */
    use HasFactory;

    protected $attributes = [
        'attempts' => 0,
    ];

    protected $hidden = ['code_hash'];

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    protected static function newFactory(): TenantUserOtpFactory
    {
        return TenantUserOtpFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
