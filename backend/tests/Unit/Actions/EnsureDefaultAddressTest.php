<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\EnsureDefaultAddress;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
});

test('BR-CU03 without a default the oldest active address becomes it', function (): void {
    CustomerAddress::factory()->for($this->customer)->archived()->create();
    $oldest = CustomerAddress::factory()->for($this->customer)->create();
    CustomerAddress::factory()->for($this->customer)->create();

    app(EnsureDefaultAddress::class)->handle($this->customer->id);

    expect($oldest->refresh()->is_default)->toBeTrue();
});

test('an existing default stays', function (): void {
    CustomerAddress::factory()->for($this->customer)->create();
    $default = CustomerAddress::factory()->for($this->customer)->default()->create();

    app(EnsureDefaultAddress::class)->handle($this->customer->id);

    expect(CustomerAddress::query()->where('is_default', true)->pluck('id')->all())->toBe([$default->id]);
});

test('a customer without active addresses keeps none', function (): void {
    CustomerAddress::factory()->for($this->customer)->archived()->create();

    app(EnsureDefaultAddress::class)->handle($this->customer->id);

    expect(CustomerAddress::query()->where('is_default', true)->count())->toBe(0);
});
