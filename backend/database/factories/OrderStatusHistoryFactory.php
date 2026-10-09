<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderStatusHistory> */
final class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * The creation entry: nothing → pending, by the customer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => OrderFactory::new(),
            'from_status' => null,
            'to_status' => OrderStatus::Pending,
            'actor_type' => ActorType::Customer,
            'actor_id' => null,
            'note' => null,
        ];
    }
}
