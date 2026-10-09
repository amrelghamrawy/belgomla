<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subdomain' => 'store'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'مكتبة '.fake()->lastName(),
            'status' => TenantStatus::Active,
            'vertical' => Vertical::SchoolBooks,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            if ($tenant->settings()->doesntExist()) {
                $tenant->settings()->create([]);
            }
        });
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }
}
