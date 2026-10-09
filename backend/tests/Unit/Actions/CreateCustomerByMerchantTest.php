<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\CreateCustomerByMerchant;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU08 the customer is invited, has no password, keeps who created them, and is audited', function (): void {
    $owner = $this->makeOwner();

    $customer = app(CreateCustomerByMerchant::class)->handle(new CustomerData('مكتبة', '+201012345678', []), $owner->id);

    expect($customer->refresh()->only(['name', 'phone', 'status', 'password', 'created_by']))
        ->toBe(['name' => 'مكتبة', 'phone' => '+201012345678', 'status' => CustomerStatus::Invited, 'password' => null, 'created_by' => $owner->id])
        ->and(AuditLog::query()->sole()->only(['action', 'entity_id', 'new_values']))
        ->toBe(['action' => 'customer.created', 'entity_id' => $customer->id, 'new_values' => ['name' => 'مكتبة', 'phone' => '+201012345678', 'status' => 'invited']]);
});

test('BR-CU03 the addresses are created with it, the first is the default', function (): void {
    $customer = app(CreateCustomerByMerchant::class)->handle(new CustomerData('مكتبة', '+201012345678', [
        new AddressData('المحل', 'الجيزة', 'الدقي', 'شارع 1'),
        new AddressData('المخزن', 'الجيزة', 'الدقي', 'شارع 2'),
    ]));

    expect(CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->pluck('is_default')->all())->toBe([true, false]);
});

test('BR-CU04 a duplicate phone is refused and nothing is written', function (): void {
    Customer::factory()->create(['phone' => '+201012345678']);

    expect(fn () => app(CreateCustomerByMerchant::class)->handle(new CustomerData('مكتبة', '+201012345678', [])))->toThrow(PhoneTaken::class);

    expect(AuditLog::query()->count())->toBe(0);
});
