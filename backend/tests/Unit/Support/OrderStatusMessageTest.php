<?php

declare(strict_types=1);

use App\Support\OrderStatusMessage;

$values = ['customer' => 'محمود', 'store' => 'مكتبة النور', 'number' => 1024, 'status' => 'مؤكد', 'total' => '540.00', 'reason' => 'نفاد الكمية'];

test('BR-N03 every notifiable status has a default text that fills its placeholders', function (string $status) use ($values): void {
    $text = OrderStatusMessage::render($status, null, $values);

    expect($text)->toContain('محمود')->toContain('1024')->toContain('مكتبة النور')->not->toContain('{')
        ->and(OrderStatusMessage::unknownPlaceholders(OrderStatusMessage::DEFAULTS[$status], $status))->toBe([]);
})->with(['placed', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled']);

test('BR-O11 placed is a text of its own, not a status a store can choose to notify', function (): void {
    expect(array_keys(OrderStatusMessage::DEFAULTS))->toBe(['placed', ...OrderStatusMessage::NOTIFIABLE])
        ->and(OrderStatusMessage::NOTIFIABLE)->not->toContain('placed');
});

test('BR-N03 the store wording replaces the default and every placeholder is filled', function () use ($values): void {
    expect(OrderStatusMessage::render('confirmed', '{اسم_العميل}: طلب {رقم_الطلب} {حالة_الطلب} بقيمة {الإجمالي} من {اسم_المتجر}', $values))
        ->toBe('محمود: طلب 1024 مؤكد بقيمة 540.00 من مكتبة النور');
});

test('BR-N03 the cancel reason fills only the cancelled text, an empty one leaves nothing behind', function () use ($values): void {
    expect(OrderStatusMessage::render('cancelled', 'ألغي: {سبب_الإلغاء}', $values))->toBe('ألغي: نفاد الكمية')
        ->and(OrderStatusMessage::render('cancelled', 'ألغي {سبب_الإلغاء}', [...$values, 'reason' => null]))->toBe('ألغي');
});

test('BR-N03 unknown placeholders are listed once, in order, and the reason is unknown outside cancelled', function (): void {
    expect(OrderStatusMessage::unknownPlaceholders('{name} {اسم_العميل} {name} {سبب_الإلغاء}', 'confirmed'))->toBe(['{name}', '{سبب_الإلغاء}'])
        ->and(OrderStatusMessage::unknownPlaceholders('{سبب_الإلغاء}', 'cancelled'))->toBe([]);
});

test('BR-N03 every default fits the 500-character limit', function (): void {
    expect(array_filter(OrderStatusMessage::DEFAULTS, fn (string $text): bool => mb_strlen($text) > OrderStatusMessage::MAX_LENGTH))->toBe([]);
});
