<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\OrderReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A return row only: no lines, no stock moved and the order's `returned_total` not changed (RecordReturn does
 * that), so tests that need a consistent order go through the action.
 *
 * @extends Factory<OrderReturn>
 */
final class OrderReturnFactory extends Factory
{
    protected $model = OrderReturn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => OrderFactory::new()->delivered(),
            'number' => 1,
            'total' => '100.00',
            'refund_amount' => '0.00',
            'reason' => 'مرتجع نهاية الموسم',
            'returned_at' => now(),
            'recorded_by' => null,
        ];
    }

    public function voided(string $reason = 'تسجيل خاطئ'): static
    {
        return $this->state(['voided_at' => now(), 'void_reason' => $reason]);
    }
}
