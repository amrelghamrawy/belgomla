<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A line snapshotting its SKU as it is now; quantity_base and line_total follow quantity, the SKU's conversion
 * factor and unit_price unless given.
 *
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => OrderFactory::new(),
            'sku_id' => SkuFactory::new(),
            'product_id' => fn (array $attributes): int => $this->sku($attributes)->product_id,
            'product_name' => fn (array $attributes): string => $this->sku($attributes)->product->name,
            'sku_code' => fn (array $attributes): string => $this->sku($attributes)->code,
            'sku_name' => fn (array $attributes): string => $this->sku($attributes)->name ?? $this->sku($attributes)->product->name,
            'unit_name' => fn (array $attributes): string => $this->sku($attributes)->sellingUnit->name,
            'conversion_factor' => fn (array $attributes): int => $this->sku($attributes)->conversion_factor,
            'quantity' => 1,
            'quantity_base' => fn (array $attributes): int => $this->int($attributes['quantity']) * $this->int($attributes['conversion_factor']),
            'cover_price' => null,
            'unit_price' => '100.00',
            'discount_percent' => null,
            'tier_min_quantity' => null,
            'line_total' => fn (array $attributes): string => Money::times($this->string($attributes['unit_price']), $this->int($attributes['quantity'])),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function sku(array $attributes): Sku
    {
        return Sku::query()->with(['product', 'sellingUnit'])->findOrFail($this->int($attributes['sku_id']));
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '0.00';
    }
}
