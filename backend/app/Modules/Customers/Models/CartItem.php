<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SKU in a cart, in selling units (BR-U04), unique per cart. No price is stored (BR-PR06).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cart_id
 * @property int $sku_id
 * @property int $quantity
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Cart $cart
 * @property-read Sku $sku
 */
final class CartItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The catalog SKU of this line. Customers reads it (with its product, unit, tiers and stock) to price the
     * cart; it never writes catalog rows.
     *
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
