<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantUser> */
class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => TenantUser::ROLE_OWNER,
            'status' => TenantUser::STATUS_ACTIVE,
        ];
    }
}
