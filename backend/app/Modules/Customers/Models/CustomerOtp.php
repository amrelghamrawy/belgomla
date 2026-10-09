<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerOtpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-CU07: one code sent to a phone. Only the hash is stored; valid 10 minutes, 5 attempts, single use.
 * The channel is the one asked for (WhatsApp, or SMS from "send by SMS instead"); Messaging may still fall back.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $phone E.164
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property string $channel
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CustomerOtp extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerOtpFactory> */
    use HasFactory;

    protected $attributes = [
        'attempts' => 0,
    ];

    protected $hidden = ['code_hash'];

    protected static function newFactory(): CustomerOtpFactory
    {
        return CustomerOtpFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
