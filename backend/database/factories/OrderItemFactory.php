<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A line snapshotting its SKU and unit (the default unit unless `sku_unit_id` is given) as they are now; quantity_base and
 * line_total follow quantity, the unit's conversion factor and unit_price unless given.
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
            'sku_unit_id' => fn (array $attributes): int => SkuFactory::defaultUnitIdOf($attributes['sku_id']),
            'product_id' => fn (array $attributes): int => $this->sku($attributes)->product_id,
            'product_name' => fn (array $attributes): string => $this->sku($attributes)->product->name,
            'sku_code' => fn (array $attributes): string => $this->sku($attributes)->code,
            'sku_name' => fn (array $attributes): string => $this->sku($attributes)->name ?? $this->sku($attributes)->product->name,
            'unit_name' => fn (array $attributes): string => $this->skuUnit($attributes)->unit->name,
            'conversion_factor' => fn (array $attributes): int => $this->skuUnit($attributes)->conversion_factor,
            'quantity' => 1,
            'quantity_base' => fn (array $attributes): int => $this->int($attributes['quantity']) * $this->int($attributes['conversion_factor']),
            'cover_price' => null,
            'unit_price' => '100.00',
            'list_unit_price' => fn (array $attributes): string => $this->string($attributes['unit_price']),
            'discount_percent' => null,
            'tier_min_quantity' => null,
            'line_total' => fn (array $attributes): string => Money::times($this->string($attributes['unit_price']), $this->int($attributes['quantity'])),
        ];
    }

    /** BR-O12: the merchant removed the line. */
    /** BR-O14: a pre-order line, holding no stock, `$converted` selling units of it already in a normal order. */
    public function preorderLine(int $converted = 0): self
    {
        return $this->state(['reserved' => false, 'converted_quantity' => $converted]);
    }

    public function removed(): self
    {
        return $this->state(['removed_at' => now()]);
    }

    /** BR-O13: the line went out with this many selling units (all of it unless given). */
    public function delivered(?int $quantity = null): self
    {
        return $this->state(fn (array $attributes): array => ['delivered_quantity' => $quantity ?? $attributes['quantity']]);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function sku(array $attributes): Sku
    {
        return Sku::query()->with('product')->findOrFail($this->int($attributes['sku_id']));
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function skuUnit(array $attributes): SkuUnit
    {
        return SkuUnit::query()->with('unit')->findOrFail($this->int($attributes['sku_unit_id']));
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
