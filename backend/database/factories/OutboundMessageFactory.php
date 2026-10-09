<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboundMessage> */
final class OutboundMessageFactory extends Factory
{
    protected $model = OutboundMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'to_phone' => '+201012345678',
            'purpose' => MessagePurpose::Otp,
            'channel' => Channel::WhatsApp,
            'provider' => Provider::Hypersender,
            'status' => MessageStatus::Queued,
            'body' => 'رمز التحقق: 123456',
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(['channel' => Channel::WhatsApp]);
    }

    public function sms(): static
    {
        return $this->state(['channel' => Channel::Sms]);
    }

    public function queued(?string $providerRequestId = 'a0816120-7e37-4e8b-8cf3-92deb2cdc133'): static
    {
        return $this->state(['status' => MessageStatus::Queued, 'provider_request_id' => $providerRequestId]);
    }

    public function sent(string $providerMessageId = '3EB0155CFDC2464F72FD75'): static
    {
        return $this->state(['status' => MessageStatus::Sent, 'provider_message_id' => $providerMessageId]);
    }

    public function delivered(string $providerMessageId = '3EB0155CFDC2464F72FD75'): static
    {
        return $this->state(['status' => MessageStatus::Delivered, 'provider_message_id' => $providerMessageId, 'body' => null]);
    }

    public function failed(string $errorCode = 'send_failed'): static
    {
        return $this->state(['status' => MessageStatus::Failed, 'error_code' => $errorCode, 'body' => null]);
    }

    public function fellBack(): static
    {
        return $this->state(['status' => MessageStatus::FellBack, 'error_code' => 'not_on_whatsapp', 'body' => null]);
    }
}
