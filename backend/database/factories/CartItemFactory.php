<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
final class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => CartFactory::new(),
            'sku_id' => SkuFactory::new(),
            'quantity' => 1,
            'kind' => CartLineKind::Order,
        ];
    }

    /** BR-O14: a pre-order line (a Coming Soon product with pre-orders on, D88). */
    public function preorder(): static
    {
        return $this->state(['kind' => CartLineKind::Preorder]);
    }
}
