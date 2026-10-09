<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Orders\Models\TenantOrderCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantOrderCounter> */
final class TenantOrderCounterFactory extends Factory
{
    protected $model = TenantOrderCounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'next_number' => 1,
        ];
    }
}
