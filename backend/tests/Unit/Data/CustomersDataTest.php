<?php

declare(strict_types=1);

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Data\GroupAssignmentData;
use App\Modules\Customers\Data\RegistrationData;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Http\Requests\AddCartItemRequest;
use App\Modules\Customers\Http\Requests\AssignCustomersRequest;
use App\Modules\Customers\Http\Requests\ListCustomerPricesRequest;
use App\Modules\Customers\Http\Requests\RegisterCustomerRequest;
use App\Modules\Customers\Http\Requests\SaveAddressRequest;
use App\Modules\Customers\Http\Requests\SetCustomerPriceRequest;
use App\Modules\Customers\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerGroupRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;

/**
 * @template T of Illuminate\Foundation\Http\FormRequest
 *
 * @param  class-string<T>  $class
 * @return T
 */
function validatedCustomersRequest(string $class, array $input): Illuminate\Foundation\Http\FormRequest
{
    $request = $class::create('/', 'POST', $input);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

test('BR-CU04 registration data trims the name and normalises the phone to E.164', function (): void {
    $request = validatedCustomersRequest(RegisterCustomerRequest::class, ['name' => '  محمود  ', 'phone' => '010 1234 5678', 'password' => ' secret123 ']);

    expect(RegistrationData::fromRequest($request))->toEqual(new RegistrationData('محمود', '+201012345678', ' secret123 '));
});

test('BR-CU03 address data: trimmed text, E.164 phone, empty landmark is null, default flag', function (): void {
    $request = validatedCustomersRequest(SaveAddressRequest::class, [
        'label' => ' المحل ', 'governorate' => 'القاهرة', 'city' => 'نصر', 'address_line' => 'شارع 1',
        'landmark' => '  ', 'phone' => '01112345678', 'is_default' => '1',
    ]);

    expect(AddressData::fromRequest($request))->toEqual(new AddressData('المحل', 'القاهرة', 'نصر', 'شارع 1', null, '+201112345678', true));
});

test('BR-CU03 an address without optional fields', function (): void {
    $request = validatedCustomersRequest(SaveAddressRequest::class, ['label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'نصر', 'address_line' => 'شارع 1']);

    expect(AddressData::fromRequest($request))->toEqual(new AddressData('المحل', 'القاهرة', 'نصر', 'شارع 1'));
});

test('BR-CU08 a merchant-created customer carries the phone and the addresses', function (): void {
    $request = validatedCustomersRequest(StoreCustomerRequest::class, ['name' => 'مكتبة', 'phone' => '+20 10 1234 5678', 'addresses' => [
        ['label' => 'المحل', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => 'شارع 2'],
    ]]);

    expect(CustomerData::fromRequest($request))->toEqual(new CustomerData('مكتبة', '+201012345678', [new AddressData('المحل', 'الجيزة', 'الدقي', 'شارع 2')]));
});

test('BR-CU08 a customer created without addresses has an empty list', function (): void {
    $request = validatedCustomersRequest(StoreCustomerRequest::class, ['name' => 'مكتبة', 'phone' => '01012345678']);

    expect(CustomerData::fromRequest($request)->addresses)->toBe([]);
});

test('D54 an update without addresses keeps them (null), with addresses carries their ids', function (): void {
    $without = validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة']);
    $with = validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة', 'addresses' => [
        ['id' => '7', 'label' => 'المحل', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => 'شارع 2'],
    ]]);

    expect(CustomerData::fromRequest($without))->toEqual(new CustomerData('مكتبة', null, null))
        ->and(CustomerData::fromRequest($with)->addresses)->toEqual([new AddressData('المحل', 'الجيزة', 'الدقي', 'شارع 2', id: 7)]);
});

test('BR-U05 a cart line is two integers', function (): void {
    $request = validatedCustomersRequest(AddCartItemRequest::class, ['sku_id' => '12', 'quantity' => '5']);

    expect(CartLineData::fromRequest($request))->toEqual(new CartLineData(12, 5, CartLineKind::Order));
});

test('BR-O14 a cart line may be a pre-order line', function (): void {
    $request = validatedCustomersRequest(AddCartItemRequest::class, ['sku_id' => '12', 'quantity' => '5', 'kind' => 'preorder']);

    expect(CartLineData::fromRequest($request))->toEqual(new CartLineData(12, 5, CartLineKind::Preorder))
        ->and(validatedCustomersRequest(App\Modules\Customers\Http\Requests\UpdateCartItemRequest::class, ['quantity' => '5', 'kind' => 'preorder'])->kind())->toBe(CartLineKind::Preorder)
        ->and(validatedCustomersRequest(App\Modules\Customers\Http\Requests\UpdateCartItemRequest::class, ['quantity' => '5'])->kind())->toBe(CartLineKind::Order)
        ->and(validatedCustomersRequest(App\Modules\Customers\Http\Requests\RemoveCartItemRequest::class, ['kind' => 'preorder'])->kind())->toBe(CartLineKind::Preorder)
        ->and(validatedCustomersRequest(App\Modules\Customers\Http\Requests\RemoveCartItemRequest::class, [])->kind())->toBe(CartLineKind::Order);
});

test('BR-C05 BR-C06 credit terms come as a 2-decimal limit and integer days, with which ones were sent', function (): void {
    $sent = CustomerData::fromRequest(validatedCustomersRequest(StoreCustomerRequest::class, ['name' => 'مكتبة', 'phone' => '01012345678', 'credit_limit' => '1500.5', 'payment_terms_days' => '30']));
    $cleared = CustomerData::fromRequest(validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة', 'credit_limit' => null]));
    $missing = CustomerData::fromRequest(validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة']));

    expect([$sent->creditLimit, $sent->paymentTermsDays, $sent->creditTermsSent])->toBe(['1500.50', 30, ['credit_limit', 'payment_terms_days']])
        ->and([$cleared->creditLimit, $cleared->creditTermsSent])->toBe([null, ['credit_limit']])
        ->and($missing->creditTermsSent)->toBe([]);
});

test('BR-PR12 customer data says whether a group was sent, null being no group', function (): void {
    $this->actingAsOwner();
    $sent = CustomerData::fromRequest(validatedCustomersRequest(StoreCustomerRequest::class, ['name' => 'مكتبة', 'phone' => '01012345678', 'customer_group_id' => '7']));
    $cleared = CustomerData::fromRequest(validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة', 'customer_group_id' => null]));
    $missing = CustomerData::fromRequest(validatedCustomersRequest(UpdateCustomerRequest::class, ['name' => 'مكتبة']));

    expect([$sent->groupId, $sent->groupSent])->toBe([7, true])
        ->and([$cleared->groupId, $cleared->groupSent])->toBe([null, true])
        ->and([$missing->groupId, $missing->groupSent])->toBe([null, false]);
});

test('BR-PR12 group data trims the name and normalises the percent; a patch leaves out what was not sent', function (): void {
    $created = CustomerGroupData::fromRequest(validatedCustomersRequest(StoreCustomerGroupRequest::class, ['name' => ' موزع ', 'discount_percent' => '12.5']));
    $patched = CustomerGroupData::fromRequest(validatedCustomersRequest(UpdateCustomerGroupRequest::class, ['sort_order' => '3', 'status' => 'active']));

    expect($created)->toEqual(new CustomerGroupData('موزع', '12.50', null))
        ->and($patched)->toEqual(new CustomerGroupData(null, null, 3, CustomerGroupStatus::Active));
});

test('BR-PR12 assignment data carries the ids, or the list filter', function (): void {
    $byIds = GroupAssignmentData::fromRequest(validatedCustomersRequest(AssignCustomersRequest::class, ['customer_ids' => ['4', 9]]));
    $byFilter = GroupAssignmentData::fromRequest(validatedCustomersRequest(AssignCustomersRequest::class, ['filter' => ['q' => ' النور ', 'status' => 'active', 'group' => '2']]));

    expect($byIds)->toEqual(new GroupAssignmentData([4, 9]))
        ->and($byFilter)->toEqual(new GroupAssignmentData(null, 'النور', CustomerStatus::Active, 2));
});

test('BR-PR13 a price request normalises the price and drops a blank note', function (): void {
    $request = validatedCustomersRequest(SetCustomerPriceRequest::class, ['unit_price' => '82.5', 'note' => '  ']);
    $list = validatedCustomersRequest(ListCustomerPricesRequest::class, ['q' => ' علوم ']);

    expect([$request->unitPrice(), $request->note()])->toBe(['82.50', null])
        ->and($list->search())->toBe('علوم')
        ->and(validatedCustomersRequest(ListCustomerPricesRequest::class, [])->search())->toBeNull();
});
