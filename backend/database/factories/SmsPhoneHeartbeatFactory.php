<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Messaging\Models\SmsPhoneHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SmsPhoneHeartbeat> */
final class SmsPhoneHeartbeatFactory extends Factory
{
    protected $model = SmsPhoneHeartbeat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_id' => fake()->uuid(),
            'last_seen_at' => now(),
        ];
    }
}
