<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-CU08: the one-time activation link of a merchant-created customer. One row per customer; a re-send rotates
 * the token (only its sha256 is stored), so only the latest link works. Valid 7 days.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int $sent_count
 * @property CarbonImmutable|null $last_sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 */
final class CustomerInvitation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerInvitationFactory> */
    use HasFactory;

    protected $attributes = [
        'sent_count' => 0,
    ];

    protected $hidden = ['token_hash'];

    protected static function newFactory(): CustomerInvitationFactory
    {
        return CustomerInvitationFactory::new();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'sent_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }
}
