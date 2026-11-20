<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Collections\Models\CustomerCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A credit row only, nothing applied (`remaining` = `amount` unless given). KeepAsCredit creates the matching refund
 * collection; tests that need it go through the action.
 *
 * @extends Factory<CustomerCredit>
 */
final class CustomerCreditFactory extends Factory
{
    protected $model = CustomerCredit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'source_return_id' => null,
            'amount' => '100.00',
            'remaining' => fn (array $attributes): mixed => $attributes['amount'],
            'note' => null,
            'created_by' => null,
        ];
    }

    public function voided(string $reason = 'تسجيل خاطئ'): static
    {
        return $this->state(['voided_at' => now(), 'void_reason' => $reason]);
    }
}
