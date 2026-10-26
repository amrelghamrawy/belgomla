<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Casts\E164Phone;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A storefront customer of one tenant (BR-CU01, BR-CU04): logs in with phone + password on the `customer` guard.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $phone E.164
 * @property string|null $password null until the customer sets one (BR-CU08)
 * @property string|null $remember_token
 * @property CustomerStatus $status
 * @property CarbonImmutable|null $phone_verified_at
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $blocked_at
 * @property string|null $blocked_reason
 * @property CustomerStatus|null $status_before_block
 * @property string|null $credit_limit null = no limit (BR-C05)
 * @property int|null $payment_terms_days null = due on delivery (BR-C06)
 * @property bool $order_notifications_opt_out the customer turned off order status messages (BR-N03)
 * @property int|null $customer_group_id the customer's wholesale level (BR-PR12); kept when the group is archived
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, CustomerAddress> $addresses
 * @property-read CustomerInvitation|null $invitation
 * @property-read Cart|null $cart
 * @property-read CustomerGroup|null $group
 * @property-read Collection<int, CustomerSkuPrice> $skuPrices
 */
final class Customer extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    /**
     * All addresses, archived included; the default first.
     *
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * @return HasOne<CustomerInvitation, $this>
     */
    public function invitation(): HasOne
    {
        return $this->hasOne(CustomerInvitation::class);
    }

    /**
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * BR-PR12: the customer's group, archived or not (PricingContextQuery ignores an archived one).
     *
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * BR-PR13: the prices negotiated with the customer, one per SKU.
     *
     * @return HasMany<CustomerSkuPrice, $this>
     */
    public function skuPrices(): HasMany
    {
        return $this->hasMany(CustomerSkuPrice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => E164Phone::class,
            'password' => 'hashed',
            'status' => CustomerStatus::class,
            'phone_verified_at' => 'datetime',
            'blocked_at' => 'datetime',
            'status_before_block' => CustomerStatus::class,
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'order_notifications_opt_out' => 'boolean',
            'customer_group_id' => 'integer',
        ];
    }
}
