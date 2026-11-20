<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<Promotion> */
final class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /**
     * A live promotion: 15% off, started an hour ago, ending in a week, on an explicit SKU list (none yet; see `covering()`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'عرض '.fake()->unique()->numerify('###'),
            'discount_percent' => '15.00',
            'starts_at' => Date::now()->subHour(),
            'ends_at' => Date::now()->addWeek(),
            'filter' => null,
            'status' => PromotionStatus::Scheduled,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => PromotionStatus::Draft]);
    }

    public function active(): static
    {
        return $this->state(['status' => PromotionStatus::Active]);
    }

    /** Starts tomorrow. */
    public function upcoming(): static
    {
        return $this->state(['starts_at' => Date::now()->addDay(), 'ends_at' => Date::now()->addWeek()]);
    }

    public function ended(): static
    {
        return $this->state(['status' => PromotionStatus::Ended, 'starts_at' => Date::now()->subWeek(), 'ends_at' => Date::now()->subDay()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PromotionStatus::Cancelled, 'cancelled_at' => Date::now()]);
    }

    public function percent(string $discountPercent): static
    {
        return $this->state(['discount_percent' => $discountPercent]);
    }

    /** Covers these SKUs (the materialised promotion_skus rows). */
    public function covering(Sku ...$skus): static
    {
        return $this->afterCreating(function (Promotion $promotion) use ($skus): void {
            $promotion->skus()->attach(array_map(fn (Sku $sku): int => $sku->id, $skus));
        });
    }
}
