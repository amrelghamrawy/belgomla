<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Models\MerchantNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantNotification> */
final class MerchantNotificationFactory extends Factory
{
    protected $model = MerchantNotification::class;

    /**
     * An unread new-order notification for every owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_user_id' => null,
            'type' => NotificationType::NewOrder,
            'data' => ['order_number' => fake()->numberBetween(1, 9999), 'customer_name' => fake()->name(), 'order_total' => '540.00'],
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }

    public function customerCancelled(): static
    {
        return $this->state(['type' => NotificationType::CustomerCancelled]);
    }
}
