<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\AddressLocked;
use App\Modules\Orders\Exceptions\CannotCancel;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Exceptions\OrderHasCollections;
use App\Modules\Orders\Exceptions\RefundRequired;
use App\Modules\Orders\Exceptions\ReturnAlreadyVoided;
use App\Modules\Orders\Exceptions\ReturnExceedsDelivered;
use App\Modules\Orders\Exceptions\ReturnHasRefund;
use App\Modules\Orders\Exceptions\ReturnNotAllowed;
use App\Support\ApiException;
use Carbon\CarbonImmutable;

test('every orders exception fixes its status, code and arabic message', function (ApiException $e, int $status, string $code, string $message): void {
    expect([$e->status, $e->errorCode, $e->getMessage()])->toBe([$status, $code, $message]);
})->with([
    'checkout failed' => [CheckoutFailed::with([]), 422, 'checkout_failed', 'تعذر إتمام الطلب، راجع العناصر التالية'],
    'empty cart' => [CheckoutFailed::emptyCart(), 422, 'cart_empty', 'السلة فارغة'],
    'address unavailable' => [CheckoutFailed::addressUnavailable(), 422, 'validation_failed', 'عنوان التوصيل غير موجود'],
    'store unavailable' => [CheckoutFailed::storeUnavailable(), 503, 'store_unavailable', 'المتجر غير متاح حاليًا'],
    'customer not active' => [CheckoutFailed::customerNotActive(CustomerStatus::Blocked), 403, 'blocked', 'لا يمكن إتمام الطلب، حالة حسابك «محظور»'],
    'invalid transition' => [InvalidOrderTransition::from(OrderStatus::Preparing, OrderStatus::Confirmed), 409, 'invalid_transition', 'لا يمكن نقل الطلب من «قيد التجهيز» إلى «مؤكد»'],
    'customer cancel after confirmation' => [CannotCancel::byCustomer(OrderStatus::Confirmed), 409, 'invalid_transition', 'لا يمكن إلغاء الطلب بعد تأكيده، تواصل مع المتجر'],
    'cancel a terminal order' => [CannotCancel::terminal(OrderStatus::Delivered), 409, 'invalid_transition', 'لا يمكن إلغاء طلب حالته «تم التوصيل»'],
    'has collections' => [OrderHasCollections::amount('100.00'), 409, 'has_collections', 'تم تحصيل 100.00 من هذا الطلب، ألغِ التحصيلات أولًا'],
    'address locked' => [AddressLocked::at(OrderStatus::OutForDelivery), 409, 'address_locked', 'لا يمكن تعديل عنوان التوصيل لطلب حالته «خرج للتوصيل»'],
    'return not allowed' => [ReturnNotAllowed::status(OrderStatus::Confirmed), 409, 'return_not_allowed', 'لا يمكن تسجيل مرتجع على طلب حالته «مؤكد»، المرتجع للطلبات المسلّمة فقط'],
    'return exceeds delivered' => [ReturnExceedsDelivered::items([]), 422, 'return_exceeds_delivered', 'الكمية المرتجعة أكبر من الكمية المسلّمة المتبقية'],
    'refund required' => [RefundRequired::amount('180.00'), 422, 'refund_required', 'تم تحصيل أكثر من قيمة الطلب بعد المرتجع، يجب رد 180.00 للعميل'],
    'return has refund' => [ReturnHasRefund::amount('180.00'), 409, 'return_has_refund', 'تم رد 180.00 للعميل عن هذا المرتجع، ألغِ الرد من التحصيلات أولًا'],
    'return already voided' => [ReturnAlreadyVoided::at(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC')), 409, 'already_voided', 'تم إلغاء هذا المرتجع من قبل'],
]);

test('BR-PR06 checkout failures list every problem in items and the minimum order value in details', function (): void {
    $e = CheckoutFailed::with(
        [CheckoutProblem::insufficientStock(501, 12, 7), CheckoutProblem::belowMoq(777, 5), CheckoutProblem::productInactive(802)],
        ['required' => '1000.00', 'current' => '700.00'],
    );

    expect($e->items)->toBe([
        ['sku_id' => 501, 'reason' => 'insufficient_stock', 'requested' => 12, 'available' => 7],
        ['sku_id' => 777, 'reason' => 'below_moq', 'moq' => 5],
        ['sku_id' => 802, 'reason' => 'product_inactive'],
    ])->and($e->details)->toBe(['mov' => ['required' => '1000.00', 'current' => '700.00']]);
});

test('BR-R02 an over-return lists each line with what was asked and what is left', function (): void {
    expect(ReturnExceedsDelivered::items([['order_item_id' => 7, 'requested' => 3, 'returnable' => 1]])->items)
        ->toBe([['order_item_id' => 7, 'requested' => 3, 'returnable' => 1, 'reason' => 'return_exceeds_delivered']]);
});

test('BR-R04 BR-R06 the refund and void failures carry their figures', function (): void {
    expect(RefundRequired::amount('180.00')->details)->toBe(['refund_amount' => '180.00'])
        ->and(ReturnHasRefund::amount('180.00')->details)->toBe(['refund_amount' => '180.00'])
        ->and(ReturnNotAllowed::status(OrderStatus::Pending)->details)->toBe(['status' => 'pending'])
        ->and(ReturnAlreadyVoided::at(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC'))->details)->toBe(['voided_at' => '2026-10-10T09:00:00+00:00']);
});

test('D57 a missing address is reported on the address_id field', function (): void {
    expect(CheckoutFailed::addressUnavailable()->details)->toBe(['address_id' => ['عنوان التوصيل غير موجود']]);
});
