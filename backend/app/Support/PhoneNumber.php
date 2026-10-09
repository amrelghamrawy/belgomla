<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Egyptian mobile numbers (BR-CU07, Tech §13). Accepts what customers type — `01012345678`, `+20 101 234 5678`,
 * `0020-10…`, Arabic-Indic digits — and normalises to E.164 `+201012345678`. Only the mobile prefixes
 * 010, 011, 012 and 015 are valid.
 */
final class PhoneNumber
{
    private const array ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function toE164(string $input): ?string
    {
        $value = strtr(mb_trim($input), self::ARABIC_DIGITS);
        $value = (string) preg_replace('/[\s\-().]/u', '', $value);

        $national = match (true) {
            str_starts_with($value, '+20') => '0'.mb_substr($value, 3),
            str_starts_with($value, '0020') => '0'.mb_substr($value, 4),
            str_starts_with($value, '20') && mb_strlen($value) === 12 => '0'.mb_substr($value, 2),
            str_starts_with($value, '1') && mb_strlen($value) === 10 => '0'.$value,
            default => $value,
        };

        if (preg_match('/^01[0125]\d{8}$/', $national) !== 1) {
            return null;
        }

        return '+2'.$national;
    }

    public static function isEgyptianMobile(string $input): bool
    {
        return self::toE164($input) !== null;
    }

    /**
     * WhatsApp chat id for an E.164 number: digits only, no `+`, suffixed `@c.us` (Hypersender "What is chatId").
     */
    public static function whatsAppChatId(string $e164): string
    {
        return mb_ltrim($e164, '+').'@c.us';
    }
}
