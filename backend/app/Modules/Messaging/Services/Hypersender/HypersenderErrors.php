<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Hypersender;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Enums\Channel;

/**
 * The one place that reads Hypersender response and webhook shapes (OpenAPI specs, verified 2026-10-09;
 * fixtures in tests/Fixtures/hypersender). Everything else sees a DriverResult.
 *
 * WhatsApp v2 queued request: `response_status` null = still processing, 2xx = sent (message id in
 * `response_body.key.id`, older docs say `response_body.message_id`), 404 or 422 with
 * `errors.exists: false` = the number is not on WhatsApp (BR-CU07), 423 = Meta capped the instance.
 * SMS v1 message: `status` scheduled|sent|delivered|failed|expired.
 * Webhooks: WhatsApp `message.ack` (ack -1 error, 0 pending, 1 server, 2 device, 3 read, 4 played; payload id
 * `true_<chat>_<keyId>`), SMS `message.phone.sent|delivered`, `message.send.failed|expired`.
 */
final readonly class HypersenderErrors
{
    private const array SMS_EVENTS = ['message.phone.sent', 'message.phone.delivered', 'message.send.failed', 'message.send.expired'];

    /**
     * The immediate answer to a WhatsApp send: the queued request envelope, or an HTTP error.
     *
     * @param  array<array-key, mixed>  $body
     */
    public function fromWhatsAppSend(int $httpStatus, array $body): DriverResult
    {
        $uuid = $this->string($body, 'queued_request_uuid');

        if ($httpStatus < 300 && $uuid !== null) {
            return DriverResult::queued($uuid);
        }

        return $this->whatsAppFailure($httpStatus, $body, queued: false);
    }

    /**
     * GET queued-requests/{uuid}.
     *
     * @param  array<array-key, mixed>  $body
     */
    public function fromQueuedRequest(array $body): DriverResult
    {
        $uuid = $this->string($body, 'uuid');
        $status = $body['response_status'] ?? null;

        if (! is_int($status)) {
            return DriverResult::queued($uuid);
        }

        $response = is_array($body['response_body'] ?? null) ? $body['response_body'] : [];

        if ($status >= 200 && $status < 300) {
            $key = is_array($response['key'] ?? null) ? $response['key'] : [];

            return DriverResult::sent($this->string($key, 'id') ?? $this->string($response, 'message_id'), $uuid);
        }

        $failure = $this->whatsAppFailure($status, $response, queued: true);

        return DriverResult::failed((string) $failure->errorCode, $failure->notOnWhatsApp, $uuid);
    }

    /**
     * An SMS v1 message object, from send-message, the messages list or a webhook.
     *
     * @param  array<array-key, mixed>  $message
     */
    public function fromSmsMessage(array $message): DriverResult
    {
        $id = $this->string($message, 'id');
        $requestId = $this->string($message, 'request_id');

        return match ($this->string($message, 'status')) {
            'delivered' => DriverResult::delivered($id),
            'sent' => DriverResult::sent($id, $requestId),
            'failed' => DriverResult::failed('sms_failed', providerRequestId: $requestId),
            'expired' => DriverResult::failed('sms_expired', providerRequestId: $requestId),
            default => DriverResult::queued($requestId, $id),
        };
    }

    /**
     * An HTTP error from the SMS API (400 validation, 401, 404 unknown instance, 5xx).
     */
    public function fromSmsHttpError(int $httpStatus): DriverResult
    {
        return DriverResult::failed($this->httpErrorCode($httpStatus));
    }

    /**
     * The ids a webhook refers to, or null when the event is not a delivery status we track.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{message_id: string|null, request_id: string|null}|null
     */
    public function webhookReference(Channel $channel, array $payload): ?array
    {
        $event = $this->string($payload, 'event');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($channel === Channel::WhatsApp) {
            $inner = is_array($data['payload'] ?? null) ? $data['payload'] : [];
            $id = $this->string($inner, 'id');

            return $event === 'message.ack' && $id !== null
                ? ['message_id' => self::whatsAppKeyId($id), 'request_id' => null]
                : null;
        }

        if (! in_array($event, self::SMS_EVENTS, true)) {
            return null;
        }

        $reference = ['message_id' => $this->string($data, 'id'), 'request_id' => $this->string($data, 'request_id')];

        return $reference['message_id'] === null && $reference['request_id'] === null ? null : $reference;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function fromWebhook(Channel $channel, array $payload): DriverResult
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($channel === Channel::Sms) {
            return $this->fromSmsMessage($data);
        }

        $inner = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $id = $this->string($inner, 'id');
        $keyId = $id === null ? null : self::whatsAppKeyId($id);
        $ack = $inner['ack'] ?? null;

        return match (true) {
            $ack === -1 => DriverResult::failed('whatsapp_ack_error'),
            is_int($ack) && $ack >= 2 => DriverResult::delivered($keyId),
            default => DriverResult::sent($keyId),
        };
    }

    /**
     * `true_201063178738@c.us_ABCD1234EF567890` → `ABCD1234EF567890` (the send response's `key.id`).
     */
    public static function whatsAppKeyId(string $serialized): string
    {
        $position = mb_strrpos($serialized, '_');

        return $position === false ? $serialized : mb_substr($serialized, $position + 1);
    }

    /**
     * A 404 inside a queued request means the recipient is not on WhatsApp; on the send call itself it
     * means the instance is unknown.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function whatsAppFailure(int $status, array $body, bool $queued): DriverResult
    {
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
        $messages = is_array($errors['messages'] ?? null) ? $errors['messages'] : [];
        $text = mb_strtolower(implode(' ', array_filter($messages, is_string(...))));

        $notOnWhatsApp = ($queued && $status === 404)
            || ($status === 422 && (($errors['exists'] ?? null) === false || str_contains($text, 'not on whatsapp')));

        return match (true) {
            $notOnWhatsApp => DriverResult::failed('not_on_whatsapp', notOnWhatsApp: true),
            $status === 423 => DriverResult::failed('whatsapp_capped'),
            default => DriverResult::failed($this->httpErrorCode($status)),
        };
    }

    private function httpErrorCode(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'provider_unauthorized',
            $status === 404 => 'provider_instance_not_found',
            $status === 429 => 'provider_rate_limited',
            $status >= 400 && $status < 500 => 'provider_rejected',
            default => 'provider_error',
        };
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private function string(array $array, string $key): ?string
    {
        $value = $array[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }
}
