<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A returned line of an order line; quantity_base, unit_price and line_total follow the order line unless given.
 *
 * @extends Factory<OrderReturnItem>
 */
final class OrderReturnItemFactory extends Factory
{
    protected $model = OrderReturnItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_return_id' => OrderReturnFactory::new(),
            'order_item_id' => OrderItemFactory::new(),
            'quantity' => 1,
            'quantity_base' => fn (array $attributes): int => $this->int($attributes['quantity']) * $this->item($attributes)->conversion_factor,
            'unit_price' => fn (array $attributes): string => $this->item($attributes)->unit_price,
            'line_total' => fn (array $attributes): string => Money::times($this->string($attributes['unit_price']), $this->int($attributes['quantity'])),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function item(array $attributes): OrderItem
    {
        return OrderItem::query()->findOrFail($this->int($attributes['order_item_id']));
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
