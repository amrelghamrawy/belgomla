<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\AddressLocked;
use App\Modules\Orders\Exceptions\CannotCancel;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\CustomerCannotOrder;
use App\Modules\Orders\Exceptions\DiscountRefused;
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
    'customer of a merchant order unknown' => [CheckoutFailed::customerUnavailable(), 422, 'validation_failed', 'العميل غير موجود'],
    'sku of a merchant order unknown' => [CheckoutFailed::skuUnavailable(2), 422, 'validation_failed', 'المنتج غير موجود'],
    'merchant order for a blocked customer' => [CustomerCannotOrder::because(CustomerStatus::Blocked), 409, 'customer_blocked', 'هذا العميل محظور، ألغِ الحظر أولًا لتسجيل طلب له'],
    'merchant order for an invited customer' => [CustomerCannotOrder::because(CustomerStatus::Invited), 409, 'customer_not_active', 'لا يمكن تسجيل طلب لعميل حالته «مدعو»'],
    'invalid transition' => [InvalidOrderTransition::from(OrderStatus::Preparing, OrderStatus::Confirmed), 409, 'invalid_transition', 'لا يمكن نقل الطلب من «قيد التجهيز» إلى «مؤكد»'],
    'customer cancel after confirmation' => [CannotCancel::byCustomer(OrderStatus::Confirmed), 409, 'invalid_transition', 'لا يمكن إلغاء الطلب بعد تأكيده، تواصل مع المتجر'],
    'cancel a terminal order' => [CannotCancel::terminal(OrderStatus::Delivered), 409, 'invalid_transition', 'لا يمكن إلغاء طلب حالته «تم التوصيل»'],
    'cancel a converted pre-order' => [CannotCancel::converted(), 409, 'invalid_transition', 'تم تحويل هذا الحجز بالكامل إلى طلبات، ألغِ الطلب نفسه إن لزم'],
    'move a pre-order on' => [InvalidOrderTransition::preorder(OrderStatus::Pending, OrderStatus::Confirmed), 409, 'invalid_transition', 'الحجز المسبق لا يُؤكَّد ولا يُجهَّز، يتحول إلى طلب عند توفر المنتج'],
    'discount a pre-order' => [DiscountRefused::preorder(), 409, 'order_locked', 'سعر الحجز المسبق مبدئي ويُحسب عند تحويله إلى طلب، فلا يقبل خصمًا'],
    'edit a pre-order' => [App\Modules\Orders\Exceptions\OrderEditRefused::preorder(), 409, 'order_locked', 'لا يمكن تعديل أصناف حجز مسبق، يلغيه العميل ويحجز من جديد'],
    'convert before the product is active' => [App\Modules\Orders\Exceptions\PreordersNotConvertible::productNotActive(App\Modules\Catalog\Enums\ProductStatus::ComingSoon), 409, 'product_not_active', 'فعّل المنتج وأضف مخزونه قبل تحويل الحجوزات (حالته الآن «قريبًا»)'],
    'has collections' => [OrderHasCollections::amount('100.00'), 409, 'has_collections', 'تم تحصيل 100.00 من هذا الطلب، ألغِ التحصيلات أولًا'],
    'address locked' => [AddressLocked::at(OrderStatus::OutForDelivery), 409, 'address_locked', 'لا يمكن تعديل عنوان التوصيل لطلب حالته «خرج للتوصيل»'],
    'return not allowed' => [ReturnNotAllowed::status(OrderStatus::Confirmed), 409, 'return_not_allowed', 'لا يمكن تسجيل مرتجع على طلب حالته «مؤكد»، المرتجع للطلبات المسلّمة فقط'],
    'return exceeds delivered' => [ReturnExceedsDelivered::items([]), 422, 'return_exceeds_delivered', 'الكمية المرتجعة أكبر من الكمية المسلّمة المتبقية'],
    'refund required' => [RefundRequired::amount('180.00'), 422, 'refund_required', 'تم تحصيل أكثر من قيمة الطلب بعد المرتجع، يجب رد 180.00 للعميل'],
    'return has refund' => [ReturnHasRefund::amount('180.00'), 409, 'return_has_refund', 'تم رد 180.00 للعميل عن هذا المرتجع، ألغِ الرد من التحصيلات أولًا'],
    'return already voided' => [ReturnAlreadyVoided::at(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC')), 409, 'already_voided', 'تم إلغاء هذا المرتجع من قبل'],
    'prices locked' => [DiscountRefused::locked(OrderStatus::Preparing), 409, 'order_locked', 'لا يمكن تعديل أسعار طلب حالته «قيد التجهيز»'],
    'below collected' => [DiscountRefused::belowCollected('300.00', '250.00'), 409, 'below_collected', 'تم تحصيل 300.00 من هذا الطلب، ولا يمكن أن يقل إجماليه عن ذلك'],
    'unknown line' => [DiscountRefused::unknownLine(1), 422, 'validation_failed', 'البند غير موجود في هذا الطلب'],
    'sales without a cap' => [DiscountRefused::overLimit(null), 403, 'discount_over_limit', 'غير مسموح لك بإعطاء خصم'],
    'sales above the cap' => [DiscountRefused::overLimit('10.00'), 403, 'discount_over_limit', 'أقصى خصم مسموح لك هو 10.00% على البند، ولا يمكنك إعطاء خصم على إجمالي الطلب'],
    'unit discount too large' => [App\Modules\Pricing\Exceptions\DiscountTooLarge::unit('90.00'), 422, 'discount_too_large', 'الخصم أكبر من سعر الوحدة (90.00)'],
    'order discount too large' => [App\Modules\Pricing\Exceptions\DiscountTooLarge::order('1390.00'), 422, 'discount_too_large', 'خصم الطلب أكبر من إجمالي البنود (1390.00)'],
    'lines locked' => [App\Modules\Orders\Exceptions\OrderEditRefused::locked(OrderStatus::Preparing), 409, 'order_locked', 'لا يمكن تعديل أصناف طلب حالته «قيد التجهيز»'],
    'edit below collected' => [App\Modules\Orders\Exceptions\OrderEditRefused::belowCollected('300.00', '250.00'), 409, 'below_collected', 'تم تحصيل 300.00 من هذا الطلب، ولا يمكن أن يقل إجماليه عن ذلك'],
    'edit unknown line' => [App\Modules\Orders\Exceptions\OrderEditRefused::unknownLine('lines', 0), 422, 'validation_failed', 'البند غير موجود في هذا الطلب'],
    'edit changes a sku' => [App\Modules\Orders\Exceptions\OrderEditRefused::skuChanged(0), 422, 'validation_failed', 'لا يمكن تغيير صنف بند موجود، احذفه وأضف الصنف الآخر'],
    'delivered above ordered' => [App\Modules\Orders\Exceptions\OrderEditRefused::deliveredAboveOrdered(0, 6), 422, 'validation_failed', 'الكمية المسلّمة أكبر من المطلوبة (6)'],
    'nothing delivered' => [App\Modules\Orders\Exceptions\OrderEditRefused::nothingDelivered(), 422, 'validation_failed', 'لم يتم تسليم أي صنف، ألغِ الطلب بدلًا من ذلك'],
    'quoted order unknown' => [CheckoutFailed::orderUnavailable(), 422, 'validation_failed', 'الطلب غير موجود'],
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

test('BR-O11 the field errors of a merchant order name the field', function (): void {
    expect(CheckoutFailed::customerUnavailable()->details)->toBe(['customer_id' => ['العميل غير موجود']])
        ->and(CheckoutFailed::skuUnavailable(2)->details)->toBe(['lines.2.sku_id' => ['المنتج غير موجود']])
        ->and(CustomerCannotOrder::because(CustomerStatus::PendingApproval)->details)->toBe(['status' => 'pending_approval']);
});
