<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Models\CustomerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerInvitation> */
final class CustomerInvitationFactory extends Factory
{
    protected $model = CustomerInvitation::class;

    /**
     * A live, sent invitation for an invited customer; the plain token is `token()`'s argument.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new()->invited(),
            'token_hash' => hash('sha256', fake()->unique()->sha1()),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'sent_count' => 1,
            'last_sent_at' => now(),
        ];
    }

    public function token(string $token): static
    {
        return $this->state(['token_hash' => hash('sha256', $token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subSecond()]);
    }

    public function used(): static
    {
        return $this->state(['used_at' => now()]);
    }
}
