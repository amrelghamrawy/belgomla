<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'subdomain' => 'store'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'مكتبة '.fake()->lastName(),
            'status' => Tenant::STATUS_ACTIVE,
            'vertical' => 'school-books',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            if ($tenant->settings()->doesntExist()) {
                $tenant->settings()->create([]);
            }
        });
    }

    public function suspended(): static
    {
        return $this->state(['status' => Tenant::STATUS_SUSPENDED]);
    }
}
