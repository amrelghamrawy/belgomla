<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerGroup> */
final class CustomerGroupFactory extends Factory
{
    protected $model = CustomerGroup::class;

    /**
     * An active group, 10% off the base price.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'مستوى '.fake()->unique()->numerify('###'),
            'discount_percent' => '10.00',
            'status' => CustomerGroupStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => CustomerGroupStatus::Archived]);
    }
}
