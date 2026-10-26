<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\CreateCustomerGroup;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-PR12 a group is created active with its % and audited', function (): void {
    $group = app(CreateCustomerGroup::class)->handle(new CustomerGroupData('موزع', '12.50', 3));

    expect($group->only(['tenant_id', 'name', 'discount_percent', 'sort_order']))->toBe(['tenant_id' => $this->tenant->id, 'name' => 'موزع', 'discount_percent' => '12.50', 'sort_order' => 3])
        ->and($group->status)->toBe(CustomerGroupStatus::Active)
        ->and(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'new_values']))->toEqual([
            'action' => 'customer_group.created', 'entity_type' => 'customer_groups', 'entity_id' => $group->id,
            'new_values' => ['name' => 'موزع', 'discount_percent' => '12.50', 'sort_order' => 3],
        ]);
});

test('BR-PR12 the sort order defaults to 0', function (): void {
    expect(app(CreateCustomerGroup::class)->handle(new CustomerGroupData('مكتبة', '5.00', null))->sort_order)->toBe(0);
});

test('BR-PR12 a group name is unique in the store', function (): void {
    CustomerGroup::factory()->create(['name' => 'موزع']);

    expect(fn () => app(CreateCustomerGroup::class)->handle(new CustomerGroupData('موزع', '10.00', null)))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('duplicate_name');
        });
});

test('BR-T04 another store may use the same group name', function (): void {
    $this->inTenant(fn () => CustomerGroup::factory()->create(['name' => 'موزع']), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    expect(app(CreateCustomerGroup::class)->handle(new CustomerGroupData('موزع', '10.00', null))->exists)->toBeTrue();
});
