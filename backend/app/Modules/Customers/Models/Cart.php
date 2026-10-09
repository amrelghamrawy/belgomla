<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BR-CU06: the customer's single server-side cart. It stores quantities only; prices are resolved on read.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 * @property-read Collection<int, CartItem> $items
 */
final class Cart extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Lines in the order they were first added.
     *
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
