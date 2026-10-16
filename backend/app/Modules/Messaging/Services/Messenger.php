<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Exceptions\InvalidPhoneNumber;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * The only way other modules send WhatsApp/SMS (Tech §13, rules/02 §8). Normalises the phone, applies the
 * BR-CU07 per-phone limit, logs the message for the current tenant with the driver it starts on (the store's
 * own number when connected, D70) and queues it; delivery, polling and the fallback chain happen in the
 * Messaging jobs. Named exception to "services do not write"
 * (rules/02 §2): it creates the `outbound_messages` row; every later change goes through RecordDeliveryStatus.
 */
final readonly class Messenger
{
    public function __construct(private MessagingRateLimiter $limiter, private MessageDrivers $drivers) {}

    public function send(MessagePayload $payload): OutboundMessage
    {
        $phone = PhoneNumber::toE164($payload->toPhone) ?? throw InvalidPhoneNumber::for($payload->toPhone);

        $this->limiter->ensurePhoneAllowed($phone);

        $message = OutboundMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'to_phone' => $phone,
            'purpose' => $payload->purpose,
            'channel' => $payload->channel,
            'provider' => $this->drivers->choose($payload->channel, $payload->purpose)->provider(),
            'status' => MessageStatus::Queued,
            'body' => $payload->text,
        ]);

        SendMessage::dispatch($message->id)->afterCommit();

        return $message;
    }
}
