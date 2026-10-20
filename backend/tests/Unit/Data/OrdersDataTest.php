<?php

declare(strict_types=1);

use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\DeliveryAddressData;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnFiltersData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Requests\ListDashboardOrdersRequest;
use App\Modules\Orders\Http\Requests\ListNotificationsRequest;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Requests\ListReturnsRequest;
use App\Modules\Orders\Http\Requests\MarkNotificationsReadRequest;
use App\Modules\Orders\Http\Requests\QuoteOrderRequest;
use App\Modules\Orders\Http\Requests\RecordReturnRequest;
use App\Modules\Orders\Http\Requests\StoreMerchantOrderRequest;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateDeliveryAddressRequest;
use Carbon\CarbonImmutable;

/**
 * @template T of Illuminate\Foundation\Http\FormRequest
 *
 * @param  class-string<T>  $class
 * @return T
 */
function validatedOrdersRequest(string $class, array $input, string $method = 'POST'): Illuminate\Foundation\Http\FormRequest
{
    $request = $class::create('/', $method, $input);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

test('checkout data takes the address id as an integer and trims the note', function (): void {
    $request = validatedOrdersRequest(CheckoutRequest::class, ['address_id' => '9', 'customer_note' => '  صباحًا  ']);

    expect(CheckoutData::fromRequest($request))->toEqual(new CheckoutData(9, 'صباحًا'));
});

test('checkout data without a note, or a blank one, has a null note', function (array $input): void {
    $request = validatedOrdersRequest(CheckoutRequest::class, ['address_id' => 9, ...$input]);

    expect(CheckoutData::fromRequest($request)->customerNote)->toBeNull();
})->with(['not sent' => [[]], 'blank' => [['customer_note' => '   ']], 'null' => [['customer_note' => null]]]);

test('BR-O01 transition data carries the target, the trimmed note and the collect flag', function (): void {
    $request = validatedOrdersRequest(TransitionOrderRequest::class, ['to' => 'delivered', 'note' => ' سلّم ', 'collect_full_amount' => true]);

    expect(TransitionData::fromRequest($request))->toEqual(new TransitionData(OrderStatus::Delivered, 'سلّم', true));
});

test('BR-C04 collect_full_amount only counts when delivering', function (): void {
    $request = validatedOrdersRequest(TransitionOrderRequest::class, ['to' => 'confirmed', 'collect_full_amount' => true]);

    expect(TransitionData::fromRequest($request))->toEqual(new TransitionData(OrderStatus::Confirmed, null, false));
});

test('BR-R01 return data: trimmed reason, integer lines, the refund flag and an optional utc return time', function (): void {
    $data = ReturnData::fromRequest(validatedOrdersRequest(RecordReturnRequest::class, [
        'reason' => '  نهاية الموسم ', 'returned_at' => '2026-10-09T12:00:00+03:00', 'refund' => '1',
        'items' => [['order_item_id' => '7', 'quantity' => '2'], ['order_item_id' => 9, 'quantity' => 1]],
    ]));

    expect($data->reason)->toBe('نهاية الموسم')
        ->and($data->lines)->toEqual([new ReturnLineData(7, 2), new ReturnLineData(9, 1)])
        ->and($data->refund)->toBeTrue()
        ->and($data->returnedAt?->toIso8601String())->toBe('2026-10-09T09:00:00+00:00');
});

test('BR-R04 return data without refund or time: no refund confirmed, now is decided by the action', function (): void {
    $data = ReturnData::fromRequest(validatedOrdersRequest(RecordReturnRequest::class, ['reason' => 'تالف', 'items' => [['order_item_id' => 7, 'quantity' => 1]]]));

    expect($data->refund)->toBeFalse()
        ->and($data->returnedAt)->toBeNull();
});

test('D60 the returns list filters by business days of returned_at, customer and search', function (): void {
    $filters = ReturnFiltersData::fromRequest(validatedOrdersRequest(ListReturnsRequest::class, ['from' => '2026-10-01', 'to' => '2026-10-02', 'customer_id' => '5', 'q' => ' #12 '], 'GET'));

    expect($filters->from?->toIso8601String())->toBe('2026-09-30T21:00:00+00:00')
        ->and($filters->to?->toIso8601String())->toBe('2026-10-02T20:59:59+00:00')
        ->and($filters->customerId)->toBe(5)
        ->and($filters->search)->toBe('#12');
});

test('the returns list without filters lists everything', function (): void {
    expect(ReturnFiltersData::fromRequest(validatedOrdersRequest(ListReturnsRequest::class, ['q' => '  '], 'GET')))->toEqual(new ReturnFiltersData());
});

test('BR-O02 cancel data trims the reason', function (): void {
    expect(CancelData::fromRequest(validatedOrdersRequest(CancelOrderRequest::class, ['reason' => '  نفاد الكمية '])))->toEqual(new CancelData('نفاد الكمية'));
});

test('D9 delivery address data: trimmed text, E.164 phone, blanks as null', function (): void {
    $request = validatedOrdersRequest(UpdateDeliveryAddressRequest::class, [
        'label' => ' ', 'governorate' => ' الجيزة ', 'city' => 'الدقي', 'address_line' => '5 شارع التحرير', 'landmark' => 'أمام البنك',
        'name' => ' أحمد ', 'phone' => '0111 222 3334',
    ], 'PUT');

    expect(DeliveryAddressData::fromRequest($request))->toEqual(new DeliveryAddressData('الجيزة', 'الدقي', '5 شارع التحرير', null, 'أمام البنك', 'أحمد', '+201112223334'));
});

test('D9 delivery address data without name or phone keeps them null', function (): void {
    $request = validatedOrdersRequest(UpdateDeliveryAddressRequest::class, ['governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => 'شارع 1'], 'PUT');

    expect(DeliveryAddressData::fromRequest($request))->name->toBeNull()->phone->toBeNull()->label->toBeNull()->landmark->toBeNull();
});

test('the delivery snapshot of the data has the checkout keys', function (): void {
    expect(new DeliveryAddressData('الجيزة', 'الدقي', 'شارع 1', 'المخزن')->address())
        ->toBe(['label' => 'المخزن', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => 'شارع 1', 'landmark' => null]);
});

test('dashboard filters: enums, customer, whole days, search, stale and sort', function (): void {
    $request = validatedOrdersRequest(ListDashboardOrdersRequest::class, [
        'status' => 'pending', 'collection_status' => 'unpaid', 'customer_id' => '4', 'from' => '2026-10-01', 'to' => '2026-10-09',
        'q' => ' 1024 ', 'stale' => '1', 'sort' => '-order_total',
    ], 'GET');

    expect(OrderFiltersData::fromRequest($request))->toEqual(new OrderFiltersData(
        status: OrderStatus::Pending,
        collectionStatus: CollectionStatus::Unpaid,
        customerId: 4,
        from: CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        to: CarbonImmutable::parse('2026-10-09 23:59:59.999999', 'UTC'),
        search: '1024',
        stale: true,
        sort: ['order_total', 'desc'],
    ));
});

test('dashboard filters default to everything, newest first', function (): void {
    expect(OrderFiltersData::fromRequest(validatedOrdersRequest(ListDashboardOrdersRequest::class, [], 'GET')))->toEqual(new OrderFiltersData);
});

test('the store order list takes an optional status', function (): void {
    expect(validatedOrdersRequest(ListOrdersRequest::class, ['status' => 'delivered'], 'GET')->status())->toBe(OrderStatus::Delivered)
        ->and(validatedOrdersRequest(ListOrdersRequest::class, [], 'GET')->status())->toBeNull();
});

test('the notification list reads the unread flag', function (): void {
    expect(validatedOrdersRequest(ListNotificationsRequest::class, ['unread' => '1'], 'GET')->unreadOnly())->toBeTrue()
        ->and(validatedOrdersRequest(ListNotificationsRequest::class, [], 'GET')->unreadOnly())->toBeFalse();
});

test('mark read takes the ids, or null for all', function (): void {
    expect(validatedOrdersRequest(MarkNotificationsReadRequest::class, ['ids' => ['3', 5]])->ids())->toBe([3, 5])
        ->and(validatedOrdersRequest(MarkNotificationsReadRequest::class, ['all' => true])->ids())->toBeNull();
});

test('BR-C07 return data keeps the refund as credit when asked, cash otherwise', function (): void {
    $credit = ReturnData::fromRequest(validatedOrdersRequest(RecordReturnRequest::class, ['reason' => 'x', 'refund' => true, 'refund_to' => 'credit', 'items' => [['order_item_id' => 7, 'quantity' => 1]]]));
    $cash = ReturnData::fromRequest(validatedOrdersRequest(RecordReturnRequest::class, ['reason' => 'x', 'items' => [['order_item_id' => 7, 'quantity' => 1]]]));

    expect($credit->refundTo)->toBe(App\Modules\Orders\Enums\RefundMethod::Credit)
        ->and($cash->refundTo)->toBe(App\Modules\Orders\Enums\RefundMethod::Cash);
});

test('BR-O11 merchant order data: integer ids and lines, the trimmed note and both flags', function (): void {
    $this->actingAsOwner(); // override_mov needs orders.override_mov (D84)
    $request = validatedOrdersRequest(StoreMerchantOrderRequest::class, [
        'customer_id' => '7', 'address_id' => '9', 'lines' => [['sku_id' => '3', 'quantity' => '12']], 'note' => '  بالتليفون  ', 'notify_customer' => true, 'override_mov' => '1',
    ]);

    expect(MerchantOrderData::fromRequest($request))->toEqual(new MerchantOrderData(7, 9, [['sku_id' => 3, 'quantity' => 12]], 'بالتليفون', true, true));
});

test('BR-O11 merchant order data without note or flags: no note, no message, no override', function (): void {
    $request = validatedOrdersRequest(StoreMerchantOrderRequest::class, ['customer_id' => 7, 'address_id' => 9, 'lines' => [['sku_id' => 3, 'quantity' => 1]], 'note' => '  ']);

    expect(MerchantOrderData::fromRequest($request))->toEqual(new MerchantOrderData(7, 9, [['sku_id' => 3, 'quantity' => 1]]));
});

test('BR-O11 the quote request gives the customer and the lines as integers', function (): void {
    $request = validatedOrdersRequest(QuoteOrderRequest::class, ['customer_id' => '7', 'lines' => [['sku_id' => '3', 'quantity' => '5'], ['sku_id' => 4, 'quantity' => 1]]]);

    expect([$request->customerId(), $request->lines()])->toBe([7, [['sku_id' => 3, 'quantity' => 5], ['sku_id' => 4, 'quantity' => 1]]]);
});

test('BR-O11 placement data knows whether a merchant placed the order', function (): void {
    $customer = new App\Modules\Customers\Models\Customer;

    expect(new App\Modules\Orders\Data\OrderPlacementData($customer, 1, [], App\Modules\Audit\Enums\ActorType::TenantUser, 5)->byMerchant())->toBeTrue()
        ->and(new App\Modules\Orders\Data\OrderPlacementData($customer, 1, [], App\Modules\Audit\Enums\ActorType::Customer, 5)->byMerchant())->toBeFalse();
});

test('D85 discount data: the lines with their type and value as money, the order discount and its trimmed reason', function (): void {
    $request = validatedOrdersRequest(App\Modules\Orders\Http\Requests\ApplyOrderDiscountRequest::class, [
        'items' => [['order_item_id' => '7', 'type' => 'percent', 'value' => 10], ['order_item_id' => 8, 'type' => null, 'value' => null], ['order_item_id' => 9]],
        'order' => ['type' => 'amount', 'value' => '50.5', 'reason' => '  عميل دائم  '],
    ], 'PUT');

    expect(App\Modules\Orders\Data\DiscountData::fromRequest($request))->toEqual(new App\Modules\Orders\Data\DiscountData(
        [
            new App\Modules\Orders\Data\LineDiscountData(7, App\Modules\Pricing\Enums\DiscountType::Percent, '10.00'),
            new App\Modules\Orders\Data\LineDiscountData(8),
            new App\Modules\Orders\Data\LineDiscountData(9),
        ],
        App\Modules\Pricing\Enums\DiscountType::Amount,
        '50.50',
        'عميل دائم',
    ));
});

test('D85 discount data without an order discount has no type, value or reason', function (): void {
    $request = validatedOrdersRequest(App\Modules\Orders\Http\Requests\ApplyOrderDiscountRequest::class, ['items' => [], 'order' => null], 'PUT');

    expect(App\Modules\Orders\Data\DiscountData::fromRequest($request))->toEqual(new App\Modules\Orders\Data\DiscountData())
        ->and($request->actorId())->toBe(0);
});

test('D85 merchant order data carries the line discounts by sku and the order discount', function (): void {
    $request = validatedOrdersRequest(StoreMerchantOrderRequest::class, [
        'customer_id' => 7, 'address_id' => 9,
        'lines' => [['sku_id' => '3', 'quantity' => 12, 'discount' => ['type' => 'amount', 'value' => '2']], ['sku_id' => 4, 'quantity' => 1, 'discount' => null]],
        'discount' => ['type' => 'percent', 'value' => '5', 'reason' => ' بالتليفون '],
    ]);

    expect(MerchantOrderData::fromRequest($request))->toEqual(new MerchantOrderData(
        7, 9, [['sku_id' => 3, 'quantity' => 12], ['sku_id' => 4, 'quantity' => 1]],
        lineDiscounts: [3 => ['type' => App\Modules\Pricing\Enums\DiscountType::Amount, 'value' => '2.00']],
        discountType: App\Modules\Pricing\Enums\DiscountType::Percent,
        discountValue: '5.00',
        discountReason: 'بالتليفون',
    ));
});

test('BR-O13 delivering carries the delivered quantities by order line, as integers', function (): void {
    $request = validatedOrdersRequest(TransitionOrderRequest::class, ['to' => 'delivered', 'delivered_quantities' => [['order_item_id' => '7', 'quantity' => '4'], ['order_item_id' => 9, 'quantity' => 0]]]);

    expect(TransitionData::fromRequest($request))->toEqual(new TransitionData(OrderStatus::Delivered, null, false, [7 => 4, 9 => 0]));
});

test('BR-O13 delivered quantities only count when delivering, and none sent means everything was delivered', function (string $to, array $input): void {
    $request = validatedOrdersRequest(TransitionOrderRequest::class, ['to' => $to, ...$input]);

    expect(TransitionData::fromRequest($request)->deliveredQuantities)->toBeNull();
})->with([
    'another target' => ['out_for_delivery', ['delivered_quantities' => [['order_item_id' => 7, 'quantity' => 4]]]],
    'not sent' => ['delivered', []],
]);

test('BR-O12 order edit data: every line with its id or none, integers, and the trimmed note', function (): void {
    $data = App\Modules\Orders\Data\OrderEditData::fromRequest(validatedOrdersRequest(App\Modules\Orders\Http\Requests\EditOrderLinesRequest::class, [
        'lines' => [['order_item_id' => '7', 'sku_id' => '3', 'quantity' => '12'], ['sku_id' => 5, 'quantity' => 6], ['order_item_id' => null, 'sku_id' => 8, 'quantity' => 5]],
        'note' => '  نفد الكتاب ',
    ], 'PUT'));

    expect($data)->toEqual(new App\Modules\Orders\Data\OrderEditData([
        new App\Modules\Orders\Data\OrderEditLineData(7, 3, 12),
        new App\Modules\Orders\Data\OrderEditLineData(null, 5, 6),
        new App\Modules\Orders\Data\OrderEditLineData(null, 8, 5),
    ], 'نفد الكتاب'));
});

test('BR-O12 an empty note is no note', function (): void {
    $data = App\Modules\Orders\Data\OrderEditData::fromRequest(validatedOrdersRequest(App\Modules\Orders\Http\Requests\EditOrderLinesRequest::class, ['lines' => [['sku_id' => 5, 'quantity' => 6]], 'note' => '  '], 'PUT'));

    expect($data->note)->toBeNull();
});
