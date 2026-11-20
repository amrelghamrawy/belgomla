<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\UpdateCustomerGroup;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->group = CustomerGroup::factory()->create(['name' => 'موزع', 'discount_percent' => '10.00', 'sort_order' => 1]);
});

test('BR-PR12 only the sent fields change and the change is audited', function (): void {
    app(UpdateCustomerGroup::class)->handle($this->group, new CustomerGroupData(null, '15.00', null));

    expect($this->group->refresh()->only(['name', 'discount_percent', 'sort_order']))->toBe(['name' => 'موزع', 'discount_percent' => '15.00', 'sort_order' => 1])
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toBe([
            'action' => 'customer_group.updated', 'old_values' => ['discount_percent' => '10.00'], 'new_values' => ['discount_percent' => '15.00'],
        ]);
});

test('BR-PR12 sending the same values writes nothing', function (): void {
    app(UpdateCustomerGroup::class)->handle($this->group, new CustomerGroupData('موزع', '10', 1));

    expect(AuditLog::query()->count())->toBe(0);
});

test('D89 an archived group is brought back with status active', function (): void {
    $this->group->forceFill(['status' => CustomerGroupStatus::Archived])->save();

    app(UpdateCustomerGroup::class)->handle($this->group, new CustomerGroupData(null, null, null, CustomerGroupStatus::Active));

    expect($this->group->refresh()->status)->toBe(CustomerGroupStatus::Active)
        ->and(AuditLog::query()->sole()->new_values)->toBe(['status' => 'active']);
});

test('BR-PR12 renaming to another group\'s name conflicts, keeping its own name does not', function (): void {
    CustomerGroup::factory()->create(['name' => 'مكتبة كبيرة']);

    expect(fn () => app(UpdateCustomerGroup::class)->handle($this->group, new CustomerGroupData('مكتبة كبيرة', null, null)))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->errorCode)->toBe('duplicate_name');
        })
        ->and(app(UpdateCustomerGroup::class)->handle($this->group, new CustomerGroupData('موزع', '20.00', null))->discount_percent)->toBe('20.00');
});
