<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Hypersender;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;

/**
 * SMS through the Hypersender Android app on the platform phone + SIM (D11). v1 answers synchronously with
 * the message object; while the phone has not sent it yet, `status()` looks it up by our `request_id`
 * (the row's uuid). Recipients see the platform phone number as the sender.
 */
final readonly class HypersenderSmsDriver implements MessageDriver
{
    public function __construct(private HypersenderClient $client, private HypersenderErrors $errors) {}

    public function provider(): Provider
    {
        return Provider::Hypersender;
    }

    public function send(OutboundMessage $message, MessagePayload $payload): DriverResult
    {
        $response = $this->client->sms()->post('send-message', [
            'to' => $payload->toPhone,
            'content' => $payload->text,
            'request_id' => $message->uuid,
        ]);

        if ($response->failed()) {
            return $this->errors->fromSmsHttpError($response->status());
        }

        $body = $response->json();

        return $this->errors->fromSmsMessage(is_array($body) ? $body : []);
    }

    public function status(OutboundMessage $message): DriverResult
    {
        $response = $this->client->sms()->get('messages', ['filter[request_id]' => $message->uuid]);

        if ($response->failed()) {
            return DriverResult::queued($message->uuid, $message->provider_message_id);
        }

        $body = $response->json();
        $list = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);
        $first = $list[0] ?? null;

        return is_array($first) ? $this->errors->fromSmsMessage($first) : DriverResult::queued($message->uuid, $message->provider_message_id);
    }
}
