<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A cash collection row only: the order's collected amount and status are not recomputed (RecordCollection does
 * that), so tests that need a consistent order set them with OrderFactory::withCollected().
 *
 * @extends Factory<CashCollection>
 */
final class CashCollectionFactory extends Factory
{
    protected $model = CashCollection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => OrderFactory::new(),
            'amount' => '100.00',
            'method' => CollectionMethod::Cash,
            'collected_at' => now(),
            'recorded_by' => null,
            'note' => null,
        ];
    }

    public function voided(string $reason = 'تسجيل خاطئ'): static
    {
        return $this->state(['voided_at' => now(), 'void_reason' => $reason]);
    }
}
