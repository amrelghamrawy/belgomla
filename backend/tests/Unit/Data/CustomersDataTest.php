<?php

declare(strict_types=1);

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Data\RegistrationData;
use App\Modules\Customers\Http\Requests\AddCartItemRequest;
use App\Modules\Customers\Http\Requests\RegisterCustomerRequest;
use App\Modules\Customers\Http\Requests\SaveAddressRequest;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
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

    expect(CartLineData::fromRequest($request))->toEqual(new CartLineData(12, 5));
});
