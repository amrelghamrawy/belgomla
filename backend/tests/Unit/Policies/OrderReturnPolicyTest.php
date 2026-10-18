<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Policies\OrderReturnPolicy;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->return = OrderReturn::factory()->create();
});

test('BR-R06 a merchant may void a return', function (): void {
    expect(Gate::forUser($this->owner)->inspect('void', $this->return)->allowed())->toBeTrue();
});

test('BR-R06 a customer may never void a return', function (): void {
    expect(Gate::forUser(Customer::factory()->create())->inspect('void', $this->return)->denied())->toBeTrue();
});

test('the policy is registered for returns', function (): void {
    expect(Gate::getPolicyFor(OrderReturn::class))->toBeInstanceOf(OrderReturnPolicy::class);
});
