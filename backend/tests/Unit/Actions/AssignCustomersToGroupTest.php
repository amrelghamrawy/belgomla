<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\AssignCustomersToGroup;
use App\Modules\Customers\Data\GroupAssignmentData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->group = CustomerGroup::factory()->create();
});

test('BR-PR12 the ticked customers move into the group, under one audit batch', function (): void {
    $old = CustomerGroup::factory()->create();
    $a = Customer::factory()->create(['customer_group_id' => $old->id]);
    $b = Customer::factory()->create();

    $assigned = app(AssignCustomersToGroup::class)->handle($this->group, new GroupAssignmentData([$a->id, $b->id]));

    $summary = AuditLog::query()->where('action', 'customer_group.assigned')->sole();

    expect($assigned)->toBe(2)
        ->and(Customer::query()->whereKey([$a->id, $b->id])->pluck('customer_group_id')->unique()->all())->toBe([$this->group->id])
        ->and($summary->new_values)->toBe(['assigned' => 2, 'customer_ids' => [$a->id, $b->id]])
        ->and(AuditLog::query()->where('action', 'customer.group_changed')->where('batch_id', $summary->batch_id)->count())->toBe(2);
});

test('BR-PR12 customers already in the group are left alone', function (): void {
    $in = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    $out = Customer::factory()->create();

    expect(app(AssignCustomersToGroup::class)->handle($this->group, new GroupAssignmentData([$in->id, $out->id])))->toBe(1)
        ->and(AuditLog::query()->where('action', 'customer.group_changed')->sole()->entity_id)->toBe($out->id);
});

test('BR-PR12 every customer of the list filter moves, the rest stay', function (): void {
    $match = Customer::factory()->create(['name' => 'مكتبة النور']);
    $blocked = Customer::factory()->blocked()->create(['name' => 'مكتبة الأمل']);
    $other = Customer::factory()->create(['name' => 'دار الكتب']);

    $assigned = app(AssignCustomersToGroup::class)->handle($this->group, new GroupAssignmentData(null, 'مكتبة', CustomerStatus::Active));

    expect($assigned)->toBe(1)
        ->and($match->refresh()->customer_group_id)->toBe($this->group->id)
        ->and($blocked->refresh()->customer_group_id)->toBeNull()
        ->and($other->refresh()->customer_group_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'customer_group.assigned')->sole()->new_values)->toEqual(['assigned' => 1, 'filter' => ['q' => 'مكتبة', 'status' => 'active', 'group_id' => null]]);
});

test('BR-PR12 the filter can take the members of another group', function (): void {
    $from = CustomerGroup::factory()->create();
    $member = Customer::factory()->create(['customer_group_id' => $from->id]);
    Customer::factory()->create();

    expect(app(AssignCustomersToGroup::class)->handle($this->group, new GroupAssignmentData(null, groupId: $from->id)))->toBe(1)
        ->and($member->refresh()->customer_group_id)->toBe($this->group->id);
});

test('D89 nobody joins an archived group', function (): void {
    $archived = CustomerGroup::factory()->archived()->create();
    $customer = Customer::factory()->create();

    expect(fn () => app(AssignCustomersToGroup::class)->handle($archived, new GroupAssignmentData([$customer->id])))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('group_archived');
        })
        ->and($customer->refresh()->customer_group_id)->toBeNull();
});

test('BR-T04 an id of another store refuses the whole assignment', function (): void {
    $mine = Customer::factory()->create();
    $foreign = $this->makeCustomer(tenant: $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => app(AssignCustomersToGroup::class)->handle($this->group, new GroupAssignmentData([$mine->id, $foreign->id])))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(422)->and($e->details)->toBe(['customer_ids' => ['بعض العملاء غير موجودين']]);
        })
        ->and($mine->refresh()->customer_group_id)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});
