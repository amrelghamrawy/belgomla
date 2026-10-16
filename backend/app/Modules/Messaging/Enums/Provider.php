<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Who delivered a message (D11). `meta_cloud` is reserved for the official WhatsApp API migration path (D46);
 * `wa_gateway` is the store's own linked number (D70).
 */
enum Provider: string
{
    case Hypersender = 'hypersender';
    case MetaCloud = 'meta_cloud';
    case Log = 'log';
    case WaGateway = 'wa_gateway';

    public function label(): string
    {
        return match ($this) {
            self::Hypersender => 'Hypersender',
            self::MetaCloud => 'Meta Cloud API',
            self::Log => 'سجل التطوير',
            self::WaGateway => 'رقم المتجر',
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
