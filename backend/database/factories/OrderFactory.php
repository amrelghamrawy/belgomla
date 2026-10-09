<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending order without lines and without reserved stock. Use withItems() for lines; stock is reserved
 * only by checkout (StockLedger), so tests that move stock reserve it themselves.
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 9_999_999),
            'customer_id' => CustomerFactory::new(),
            'status' => OrderStatus::Pending,
            'items_total' => '0.00',
            'order_total' => '0.00',
            'delivery_name' => fake()->name(),
            'delivery_phone' => '+2010'.fake()->numerify('########'),
            'delivery_address' => [
                'label' => 'المحل',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'address_line' => '12 شارع عباس العقاد',
                'landmark' => null,
            ],
            'customer_note' => null,
            'placed_at' => now(),
        ];
    }

    /**
     * Lines of the given SKU attributes (one line each); the totals follow the lines.
     *
     * @param  list<array<string, mixed>>  $lines  OrderItem attributes, e.g. ['sku_id' => 1, 'quantity' => 3]
     */
    public function withItems(array $lines = [[]]): static
    {
        return $this->afterCreating(function (Order $order) use ($lines): void {
            $total = '0.00';

            foreach ($lines as $line) {
                $item = OrderItemFactory::new()->for($order)->createOne($line);
                $total = Money::add($total, $item->line_total);
            }

            $order->forceFill(['items_total' => $total, 'order_total' => $total])->save();
            $order->unsetRelation('items');
        });
    }

    public function pending(): static
    {
        return $this->state(['status' => OrderStatus::Pending]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);
    }

    public function preparing(): static
    {
        return $this->state(['status' => OrderStatus::Preparing, 'confirmed_at' => now()]);
    }

    public function outForDelivery(): static
    {
        return $this->state(['status' => OrderStatus::OutForDelivery, 'confirmed_at' => now()]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => OrderStatus::Delivered, 'confirmed_at' => now(), 'delivered_at' => now()]);
    }

    public function cancelled(CancelledBy $by = CancelledBy::TenantUser, string $reason = 'نفاد الكمية'): static
    {
        return $this->state(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by_type' => $by, 'cancel_reason' => $reason]);
    }

    /** BR-INV07: pending for longer than the default 24 h threshold (D10). */
    public function stale(int $hours = 25): static
    {
        return $this->state(['status' => OrderStatus::Pending, 'placed_at' => now()->subHours($hours)]);
    }

    /** Some of the order collected already (BR-O03), as Collections (Task 7) would leave it. */
    public function withCollected(string $amount): static
    {
        return $this->state(['collected_amount' => $amount, 'collection_status' => 'partially_collected']);
    }
}
