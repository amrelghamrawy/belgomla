<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantUser> */
final class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => TenantUserRole::Owner,
            'status' => TenantUserStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => TenantUserStatus::Disabled]);
    }
}
