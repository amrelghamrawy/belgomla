<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WhatsAppConnection> */
final class WhatsAppConnectionFactory extends Factory
{
    protected $model = WhatsAppConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_key' => (string) Str::uuid(),
            'status' => ConnectionStatus::Pairing,
        ];
    }

    public function pairing(): static
    {
        return $this->state(['status' => ConnectionStatus::Pairing, 'phone' => null, 'connected_at' => null]);
    }

    public function connected(string $phone = '+201001234567'): static
    {
        return $this->state(['status' => ConnectionStatus::Connected, 'phone' => $phone, 'connected_at' => now(), 'last_event_at' => now()]);
    }

    public function disconnected(): static
    {
        return $this->state(['status' => ConnectionStatus::Disconnected, 'phone' => null, 'connected_at' => null]);
    }

    public function loggedOut(string $reason = 'logged_out'): static
    {
        return $this->state(['status' => ConnectionStatus::LoggedOut, 'phone' => '+201001234567', 'disconnect_reason' => $reason]);
    }

    public function error(string $reason = 'phone_in_use'): static
    {
        return $this->state(['status' => ConnectionStatus::Error, 'disconnect_reason' => $reason]);
    }
}
