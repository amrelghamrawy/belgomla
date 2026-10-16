<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * D70: the state of a store's linked WhatsApp number. `pairing` while the QR is shown, `connected` once
 * scanned, `disconnected` when the merchant unlinked it or the QR expired, `logged_out` when WhatsApp or the
 * phone dropped the link, `error` when the link cannot be used (the number is live on another store).
 */
enum ConnectionStatus: string
{
    case Pairing = 'pairing';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case LoggedOut = 'logged_out';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pairing => 'في انتظار مسح الرمز',
            self::Connected => 'متصل',
            self::Disconnected => 'غير مربوط',
            self::LoggedOut => 'انفصل الربط',
            self::Error => 'تعذر الربط',
        };
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
