<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * An active, verified customer with the password `password`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+2010'.fake()->unique()->numerify('########'),
            'password' => 'password',
            'status' => CustomerStatus::Active,
            'phone_verified_at' => now(),
        ];
    }

    /** Self-registered, OTP not confirmed yet (BR-CU05). */
    public function pendingVerification(): static
    {
        return $this->state(['status' => CustomerStatus::PendingVerification, 'phone_verified_at' => null]);
    }

    /** Merchant-created, invitation link not used yet: no password (BR-CU08). */
    public function invited(): static
    {
        return $this->state(['status' => CustomerStatus::Invited, 'password' => null, 'phone_verified_at' => null]);
    }

    /** Verified, waiting for the merchant (D2). */
    public function pendingApproval(): static
    {
        return $this->state(['status' => CustomerStatus::PendingApproval]);
    }

    public function blocked(CustomerStatus $before = CustomerStatus::Active, string $reason = 'لم يسدد'): static
    {
        return $this->state([
            'status' => CustomerStatus::Blocked,
            'status_before_block' => $before,
            'blocked_at' => now(),
            'blocked_reason' => $reason,
        ]);
    }
}
