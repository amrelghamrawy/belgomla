<?php

declare(strict_types=1);

namespace App\Support;

/**
 * BR-CU08: the WhatsApp/SMS text that carries a customer's invitation link. A merchant may replace the
 * default with their own wording (tenant_settings.invitation_message, null = default) using the placeholders
 * below; the link is always appended on its own last line, so no template can drop it.
 */
final class InvitationMessage
{
    public const string CUSTOMER = '{اسم_العميل}';

    public const string STORE = '{اسم_المتجر}';

    public const string DAYS = '{عدد_الأيام}';

    public const string DEFAULT = "أهلًا {اسم_العميل}، دعاك {اسم_المتجر} للطلب من متجره.\n"
        .'اضغط الرابط لتحديد كلمة المرور وتفعيل حسابك (صالح {عدد_الأيام} أيام):';

    /** Kept short: the SMS fallback (BR-CU07) bills per segment. */
    public const int MAX_LENGTH = 500;

    /** @var list<string> */
    public const array PLACEHOLDERS = [self::CUSTOMER, self::STORE, self::DAYS];

    public static function render(?string $template, string $customer, string $store, int $days, string $link): string
    {
        $text = strtr($template ?? self::DEFAULT, [self::CUSTOMER => $customer, self::STORE => $store, self::DAYS => (string) $days]);

        return mb_rtrim($text)."\n".$link;
    }

    /**
     * Every `{...}` in the template that is not one of PLACEHOLDERS, in order of appearance, without duplicates.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $template): array
    {
        preg_match_all('/\{[^{}]*\}/u', $template, $matches);

        return array_values(array_unique(array_diff($matches[0], self::PLACEHOLDERS)));
    }
}
