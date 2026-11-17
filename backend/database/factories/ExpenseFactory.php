<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
final class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spent_on' => '2026-10-10',
            'amount' => '100.00',
            'category' => ExpenseCategory::Transport,
            'note' => null,
            'created_by' => null,
        ];
    }

    public function voided(string $reason = 'تسجيل خاطئ'): static
    {
        return $this->state(['voided_at' => now(), 'void_reason' => $reason]);
    }
}
