<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\ArchiveCustomerGroup;
use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D89 a group with customers is archived once, they keep it, audited with how many', function (): void {
    $group = CustomerGroup::factory()->create();
    $customers = Customer::factory()->count(2)->create(['customer_group_id' => $group->id]);

    app(ArchiveCustomerGroup::class)->handle($group);
    app(ArchiveCustomerGroup::class)->handle($group);

    expect($group->refresh()->status)->toBe(CustomerGroupStatus::Archived)
        ->and(Customer::query()->whereKey($customers->modelKeys())->pluck('customer_group_id')->unique()->all())->toBe([$group->id])
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toBe([
            'action' => 'customer_group.archived', 'old_values' => ['status' => 'active'], 'new_values' => ['status' => 'archived', 'customers' => 2],
        ]);
});
