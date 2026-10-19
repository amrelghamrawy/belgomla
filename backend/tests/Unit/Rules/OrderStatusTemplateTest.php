<?php

declare(strict_types=1);

use App\Modules\Tenancy\Rules\OrderStatusTemplate;

function orderTemplateFailures(string $attribute, mixed $value): array
{
    $failures = [];
    new OrderStatusTemplate()->validate($attribute, $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

test('BR-N03 a template with known placeholders passes', function (): void {
    expect(orderTemplateFailures('order_status_messages.confirmed', 'أهلًا {اسم_العميل}، طلبك {رقم_الطلب} {حالة_الطلب}'))->toBe([]);
});

test('BR-N03 an unknown placeholder is refused with the allowed list', function (): void {
    expect(orderTemplateFailures('order_status_messages.delivered', 'أهلًا {name}'))->toBe([
        'متغير غير معروف: {name}. المتغيرات المتاحة: {اسم_العميل}، {اسم_المتجر}، {رقم_الطلب}، {حالة_الطلب}، {الإجمالي}.',
    ]);
});

test('BR-N03 the cancel reason is allowed only in the cancelled text', function (): void {
    expect(orderTemplateFailures('order_status_messages.cancelled', 'السبب: {سبب_الإلغاء}'))->toBe([])
        ->and(orderTemplateFailures('order_status_messages.confirmed', 'السبب: {سبب_الإلغاء}'))->toHaveCount(1);
});

test('a value that is not a string is left to the string rule', function (): void {
    expect(orderTemplateFailures('order_status_messages.confirmed', ['x']))->toBe([]);
});
