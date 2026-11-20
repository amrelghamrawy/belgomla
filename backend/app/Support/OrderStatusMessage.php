<?php

declare(strict_types=1);

namespace App\Support;

/**
 * BR-N03: the WhatsApp text that tells a customer their order moved. Each store words its own per status
 * (tenant_settings.order_status_messages, a missing or null entry = the default below) with the placeholders below;
 * `{سبب_الإلغاء}` only means something for `cancelled`, so other statuses reject it. Shaped like InvitationMessage.
 */
final class OrderStatusMessage
{
    public const string CUSTOMER = '{اسم_العميل}';

    public const string STORE = '{اسم_المتجر}';

    public const string NUMBER = '{رقم_الطلب}';

    public const string STATUS = '{حالة_الطلب}';

    public const string TOTAL = '{الإجمالي}';

    public const string REASON = '{سبب_الإلغاء}';

    /** Kept short like the invitation (D72): one WhatsApp bubble. */
    public const int MAX_LENGTH = 500;

    /**
     * The platform's wording per message: one per status a store may notify, plus `placed`, sent when a merchant
     * places an order for the customer and ticks "send a message" (BR-O11), and `discount`, sent when a merchant changes
     * the order's discounts and the store notifies it (D85; off by default), and `edited`, sent when a merchant changes the
     * order's lines (D87; on by default), and `preorder_converted`, sent whenever the merchant turns (part of) a pre-order
     * into a normal order (BR-O14, D88; per order like `placed`).
     *
     * @var array<string, string>
     */
    public const array DEFAULTS = [
        'placed' => 'أهلًا {اسم_العميل}، تم تسجيل طلبك رقم {رقم_الطلب} من {اسم_المتجر} بإجمالي {الإجمالي}. سنبلغك عند تأكيده.',
        'confirmed' => 'أهلًا {اسم_العميل}، تم تأكيد طلبك رقم {رقم_الطلب} من {اسم_المتجر} بإجمالي {الإجمالي}. سنبلغك عند خروجه للتوصيل.',
        'preparing' => 'أهلًا {اسم_العميل}، طلبك رقم {رقم_الطلب} من {اسم_المتجر} قيد التجهيز الآن.',
        'out_for_delivery' => 'أهلًا {اسم_العميل}، طلبك رقم {رقم_الطلب} من {اسم_المتجر} خرج للتوصيل وفي الطريق إليك.',
        'delivered' => 'أهلًا {اسم_العميل}، تم توصيل طلبك رقم {رقم_الطلب} من {اسم_المتجر}. شكرًا لتعاملك معنا.',
        'cancelled' => 'أهلًا {اسم_العميل}، تم إلغاء طلبك رقم {رقم_الطلب} من {اسم_المتجر}. السبب: {سبب_الإلغاء}',
        'discount' => 'أهلًا {اسم_العميل}، تم تطبيق خصم على طلبك رقم {رقم_الطلب} من {اسم_المتجر}، والإجمالي الآن {الإجمالي}.',
        'edited' => 'أهلًا {اسم_العميل}، تم تعديل أصناف طلبك رقم {رقم_الطلب} من {اسم_المتجر}، والإجمالي الآن {الإجمالي}. التفاصيل في صفحة الطلب.',
        'preorder_converted' => 'أهلًا {اسم_العميل}، توفر ما حجزته من {اسم_المتجر} وتم تسجيل طلبك رقم {رقم_الطلب} بإجمالي {الإجمالي}. سنبلغك عند تأكيده.',
    ];

    /** @var list<string> the events a store may choose to notify (order_notify_statuses): statuses, then `discount` (D85) and `edited` (D87); `placed` is per order */
    public const array NOTIFIABLE = ['confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled', 'discount', 'edited'];

    /** @var list<string> */
    public const array PLACEHOLDERS = [self::CUSTOMER, self::STORE, self::NUMBER, self::STATUS, self::TOTAL, self::REASON];

    /**
     * The text for a status: the store's template, or the default when it has none.
     *
     * @param  array{customer: string, store: string, number: int, status: string, total: string, reason: string|null}  $values
     */
    public static function render(string $status, ?string $template, array $values): string
    {
        return mb_trim(strtr($template ?? self::DEFAULTS[$status] ?? '', [
            self::CUSTOMER => $values['customer'],
            self::STORE => $values['store'],
            self::NUMBER => (string) $values['number'],
            self::STATUS => $values['status'],
            self::TOTAL => $values['total'],
            self::REASON => $values['reason'] ?? '',
        ]));
    }

    /**
     * Every `{...}` in the template that the status cannot fill, in order of appearance, without duplicates.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $template, string $status): array
    {
        preg_match_all('/\{[^{}]*\}/u', $template, $matches);
        $allowed = $status === 'cancelled' ? self::PLACEHOLDERS : array_values(array_diff(self::PLACEHOLDERS, [self::REASON]));

        return array_values(array_unique(array_diff($matches[0], $allowed)));
    }
}
