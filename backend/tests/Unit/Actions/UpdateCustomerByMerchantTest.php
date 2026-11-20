<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\UpdateCustomerByMerchant;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create(['name' => 'قديم']);
});

test('a rename is audited with the old and new name', function (): void {
    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('جديد', null, null));

    expect($this->customer->refresh()->name)->toBe('جديد')
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toBe(['action' => 'customer.updated', 'old_values' => ['name' => 'قديم'], 'new_values' => ['name' => 'جديد']]);
});

test('saving the same name without addresses writes no audit record', function (): void {
    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('قديم', null, null));

    expect(AuditLog::query()->count())->toBe(0);
});

test('D54 the address list is synced: edited by id, added without id, archived when missing', function (): void {
    $kept = CustomerAddress::factory()->for($this->customer)->default()->create(['label' => 'المحل']);
    $dropped = CustomerAddress::factory()->for($this->customer)->create();

    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('قديم', null, [
        new AddressData('المحل الكبير', 'الجيزة', 'الدقي', 'شارع 1', id: $kept->id),
        new AddressData('فرع', 'الجيزة', 'الدقي', 'شارع 2'),
    ]));

    $added = CustomerAddress::query()->latest('id')->firstOrFail();
    expect($kept->refresh()->label)->toBe('المحل الكبير')
        ->and($dropped->refresh()->status)->toBe(AddressStatus::Archived)
        ->and($added->label)->toBe('فرع')
        ->and(AuditLog::query()->sole()->old_values)->toBe(['address_ids' => [$kept->id, $dropped->id]])
        ->and(AuditLog::query()->sole()->new_values)->toBe(['address_ids' => [$kept->id, $added->id]]);
});

test('an empty address list archives them all', function (): void {
    $address = CustomerAddress::factory()->for($this->customer)->default()->create();

    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('قديم', null, []));

    expect($address->refresh()->status)->toBe(AddressStatus::Archived);
});

test('an address of another customer is not found and the whole update rolls back', function (): void {
    $foreign = CustomerAddress::factory()->create();

    expect(fn () => app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('جديد', null, [
        new AddressData('x', 'x', 'x', 'x', id: $foreign->id),
    ])))->toThrow(ModelNotFoundException::class);

    expect($this->customer->refresh()->name)->toBe('قديم')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-C05 BR-C06 changed credit terms are saved and audited as customer.credit_terms_changed', function (): void {
    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData($this->customer->name, null, null, '500.00', 30, ['credit_limit', 'payment_terms_days']));

    expect($this->customer->refresh()->only(['credit_limit', 'payment_terms_days']))->toBe(['credit_limit' => '500.00', 'payment_terms_days' => 30])
        ->and(AuditLog::query()->where('action', 'customer.credit_terms_changed')->sole()->only(['old_values', 'new_values']))->toBe([
            'old_values' => ['credit_limit' => null, 'payment_terms_days' => null],
            'new_values' => ['credit_limit' => '500.00', 'payment_terms_days' => 30],
        ]);
});

test('BR-C05 credit terms not sent stay as they are', function (): void {
    $this->customer->forceFill(['credit_limit' => '800.00'])->save();

    app(UpdateCustomerByMerchant::class)->handle($this->customer, new CustomerData('جديد', null, null));

    expect($this->customer->refresh()->credit_limit)->toBe('800.00')
        ->and(AuditLog::query()->where('action', 'customer.credit_terms_changed')->count())->toBe(0);
});
