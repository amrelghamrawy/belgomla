<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $address = CustomerAddress::factory()->create()->fresh();

    expect(array_keys($address?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'customer_id', 'label', 'governorate', 'city', 'address_line', 'landmark', 'phone',
        'is_default', 'status', 'created_at', 'updated_at',
    ]);
});

test('casts: boolean default, status enum, E.164 phone', function (): void {
    $address = CustomerAddress::factory()->default()->create(['phone' => '01112345678'])->fresh();

    expect($address?->is_default)->toBeTrue()
        ->and($address?->status)->toBe(AddressStatus::Active)
        ->and($address?->phone)->toBe('+201112345678');
});

test('a new address is active and not the default', function (): void {
    $address = new CustomerAddress;

    expect($address->status)->toBe(AddressStatus::Active)
        ->and($address->is_default)->toBeFalse();
});

test('an address belongs to its customer', function (): void {
    $customer = Customer::factory()->create();

    expect(CustomerAddress::factory()->for($customer)->create()->customer->is($customer))->toBeTrue();
});

test('BR-CU03 a customer has at most one active default address', function (): void {
    $customer = Customer::factory()->create();
    CustomerAddress::factory()->for($customer)->default()->create();

    CustomerAddress::factory()->for($customer)->default()->create();
})->throws(QueryException::class, 'customer_addresses_one_default');

test('archived addresses do not count against the default', function (): void {
    $customer = Customer::factory()->create();
    CustomerAddress::factory()->for($customer)->create(['is_default' => true, 'status' => AddressStatus::Archived]);

    expect(CustomerAddress::factory()->for($customer)->default()->create()->is_default)->toBeTrue();
});

test('two customers each have their own default', function (): void {
    CustomerAddress::factory()->default()->create();

    expect(CustomerAddress::factory()->default()->create()->is_default)->toBeTrue();
});
