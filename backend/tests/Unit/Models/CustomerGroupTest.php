<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $group = CustomerGroup::factory()->create()->fresh();

    expect(array_keys($group?->toArray() ?? []))->toBe(['id', 'tenant_id', 'name', 'discount_percent', 'status', 'sort_order', 'created_at', 'updated_at']);
});

test('casts: the percent as a 2-decimal string, the status enum, the sort order', function (): void {
    $group = CustomerGroup::factory()->archived()->create(['discount_percent' => 7.5, 'sort_order' => '4'])->fresh();

    expect($group?->discount_percent)->toBe('7.50')
        ->and($group?->status)->toBe(CustomerGroupStatus::Archived)
        ->and($group?->sort_order)->toBe(4)
        ->and($group?->isActive())->toBeFalse();
});

test('a new group is active with no discount and first in the list', function (): void {
    $group = new CustomerGroup(['name' => 'جديد']);

    expect($group->discount_percent)->toBe('0.00')
        ->and($group->status)->toBe(CustomerGroupStatus::Active)
        ->and($group->sort_order)->toBe(0);
});

test('relations: the group\'s customers', function (): void {
    $group = CustomerGroup::factory()->create();
    $member = Customer::factory()->create(['customer_group_id' => $group->id]);
    Customer::factory()->create();

    expect($group->customers->modelKeys())->toBe([$member->id])
        ->and($member->group?->id)->toBe($group->id);
});

test('D89 the database keeps the percent between 0 and 90, a known status and a unique name', function (string $sql): void {
    $group = CustomerGroup::factory()->create(['name' => 'موزع']);
    CustomerGroup::factory()->create(['name' => 'مكتبة']);

    DB::statement($sql, [$group->id]);
})->with([
    'percent above 90' => ['update customer_groups set discount_percent = 90.01 where id = ?'],
    'negative percent' => ['update customer_groups set discount_percent = -1 where id = ?'],
    'unknown status' => ["update customer_groups set status = 'deleted' where id = ?"],
    'duplicate name' => ["update customer_groups set name = 'مكتبة' where id = ?"],
])->throws(QueryException::class);
