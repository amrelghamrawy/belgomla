<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A quantity change of a line, from 5 to 8 at the same price, by the system; the order follows the line.
 *
 * @extends Factory<OrderItemChange>
 */
final class OrderItemChangeFactory extends Factory
{
    protected $model = OrderItemChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItemFactory::new(),
            'order_id' => fn (array $attributes): int => OrderItem::query()->findOrFail(is_numeric($attributes['order_item_id']) ? (int) $attributes['order_item_id'] : 0)->order_id,
            'change' => OrderItemChangeType::Quantity,
            'old_quantity' => 5,
            'new_quantity' => 8,
            'unit_price_before' => '90.00',
            'unit_price_after' => '90.00',
            'actor_id' => null,
        ];
    }

    public function added(): self
    {
        return $this->state(['change' => OrderItemChangeType::Added, 'old_quantity' => null, 'unit_price_before' => null]);
    }

    public function removed(): self
    {
        return $this->state(['change' => OrderItemChangeType::Removed, 'new_quantity' => null, 'unit_price_after' => null]);
    }

    public function deliveredShort(): self
    {
        return $this->state(['change' => OrderItemChangeType::DeliveredShort, 'old_quantity' => 8, 'new_quantity' => 6]);
    }
}
