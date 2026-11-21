<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Models\TenantUserOtp;
use App\Modules\Tenancy\Services\OtpCodes;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantUserOtp> */
final class TenantUserOtpFactory extends Factory
{
    protected $model = TenantUserOtp::class;

    /**
     * A live password reset code `123456`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_user_id' => TenantUser::factory()->withPhone(),
            'code_hash' => app(OtpCodes::class)->hash('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(['code_hash' => app(OtpCodes::class)->hash($code)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subSecond()]);
    }

    public function consumed(): static
    {
        return $this->state(['consumed_at' => now()]);
    }
}
