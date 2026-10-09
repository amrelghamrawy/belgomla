<?php

declare(strict_types=1);

use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\DeliveryAddressData;
use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Requests\ListDashboardOrdersRequest;
use App\Modules\Orders\Http\Requests\ListNotificationsRequest;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Requests\MarkNotificationsReadRequest;
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
