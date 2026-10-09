<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Customers\Services\OtpCodes;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerOtp> */
final class CustomerOtpFactory extends Factory
{
    protected $model = CustomerOtp::class;

    /**
     * A live registration code `123456`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '+201012345678',
            'purpose' => OtpPurpose::Register,
            'code_hash' => app(OtpCodes::class)->hash('123456'),
            'channel' => 'whatsapp',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(['code_hash' => app(OtpCodes::class)->hash($code)]);
    }

    public function passwordReset(): static
    {
        return $this->state(['purpose' => OtpPurpose::PasswordReset]);
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
