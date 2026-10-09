<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Lifecycle of an outbound message (Tech §13): `queued` until the provider accepts it, `sent` once it has a
 * provider message id, then `delivered` (webhook) or `failed`. A WhatsApp message that failed and was
 * re-sent as SMS ends as `fell_back`.
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case FellBack = 'fell_back';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'في الانتظار',
            self::Sent => 'أُرسلت',
            self::Delivered => 'وصلت',
            self::Failed => 'فشلت',
            self::FellBack => 'أُعيد إرسالها برسالة نصية',
        };
    }

    /** A final message ignores every later provider result. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::FellBack], true);
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
