<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\SetCustomerGroup;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->group = CustomerGroup::factory()->create();
});

test('BR-PR12 a customer joins a group, audited with the old and new group', function (): void {
    app(SetCustomerGroup::class)->handle($this->customer, $this->group->id, '7a1c6a4e-5a0e-4d8e-9c1e-2b9b3f0a1d11');

    expect($this->customer->refresh()->customer_group_id)->toBe($this->group->id)
        ->and(AuditLog::query()->sole()->only(['action', 'entity_id', 'old_values', 'new_values', 'batch_id']))->toBe([
            'action' => 'customer.group_changed', 'entity_id' => $this->customer->id,
            'old_values' => ['customer_group_id' => null], 'new_values' => ['customer_group_id' => $this->group->id], 'batch_id' => '7a1c6a4e-5a0e-4d8e-9c1e-2b9b3f0a1d11',
        ]);
});

test('BR-PR12 null takes the customer out of their group', function (): void {
    $this->customer->forceFill(['customer_group_id' => $this->group->id])->save();

    app(SetCustomerGroup::class)->handle($this->customer, null);

    expect($this->customer->refresh()->customer_group_id)->toBeNull();
});

test('BR-PR12 the same group again writes nothing', function (): void {
    $this->customer->forceFill(['customer_group_id' => $this->group->id])->save();

    app(SetCustomerGroup::class)->handle($this->customer, $this->group->id);

    expect(AuditLog::query()->count())->toBe(0);
});

test('D89 an archived group cannot be joined', function (): void {
    $archived = CustomerGroup::factory()->archived()->create();

    expect(fn () => app(SetCustomerGroup::class)->handle($this->customer, $archived->id))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('group_archived');
        });
});

test('BR-T04 a group of another store is unknown', function (): void {
    $foreign = $this->inTenant(fn () => CustomerGroup::factory()->create(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => app(SetCustomerGroup::class)->handle($this->customer, $foreign->id))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(422)->and($e->details)->toBe(['customer_group_id' => ['المستوى غير موجود']]);
        })
        ->and($this->customer->refresh()->customer_group_id)->toBeNull();
});
