<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Data;

/**
 * What a driver learned from the provider about one message: accepted and still queued, sent, delivered,
 * or failed (with `notOnWhatsApp` when the number has no WhatsApp account, BR-CU07). `final` means no
 * further polling is needed. `retryAfter` means the provider did not take the message yet and the send should
 * be tried again in that many seconds; nothing is recorded.
 */
final readonly class DriverResult
{
    public function __construct(
        public bool $ok,
        public ?string $providerRequestId = null,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public bool $notOnWhatsApp = false,
        public bool $final = false,
        public bool $delivered = false,
        public ?int $retryAfter = null,
    ) {}

    /** The provider's own send limit was reached (D70: per store number). */
    public static function busy(int $retryAfter): self
    {
        return new self(true, retryAfter: $retryAfter);
    }

    /** Accepted by the provider; the outcome comes later from polling or the webhook. */
    public static function queued(?string $providerRequestId = null, ?string $providerMessageId = null): self
    {
        return new self(true, $providerRequestId, $providerMessageId);
    }

    public static function sent(?string $providerMessageId, ?string $providerRequestId = null): self
    {
        return new self(true, $providerRequestId, $providerMessageId, final: true);
    }

    public static function delivered(?string $providerMessageId = null): self
    {
        return new self(true, providerMessageId: $providerMessageId, final: true, delivered: true);
    }

    public static function failed(string $errorCode, bool $notOnWhatsApp = false, ?string $providerRequestId = null): self
    {
        return new self(false, $providerRequestId, errorCode: $errorCode, notOnWhatsApp: $notOnWhatsApp, final: true);
    }
}
