<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Tenancy\Enums\TenantUserRole;

/*
| /api/dashboard/customer-groups (BR-PR12, V2 Task 11, D89) and the group on the customer form. `pricing.customers`
| (owner, manager); the permission matrix covers every role.
*/

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-PR12 the groups list is active first with each group\'s customer count', function (): void {
    [$archived, $group] = $this->inTenant(fn (): array => [
        CustomerGroup::factory()->archived()->create(['name' => 'أ قديم']),
        CustomerGroup::factory()->create(['name' => 'موزع', 'discount_percent' => '15.00']),
    ]);
    $this->inTenant(fn () => Customer::factory()->count(2)->create(['customer_group_id' => $group->id]));
    $this->inTenant(fn () => CustomerGroup::factory()->create(), $this->otherTenant());

    $response = $this->tenantJson('GET', '/api/dashboard/customer-groups')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', ['id' => $group->id, 'name' => 'موزع', 'discount_percent' => '15.00', 'status' => 'active', 'sort_order' => 0, 'customers_count' => 2])
        ->assertJsonPath('data.1.id', $archived->id);

    expectDocumentedShape($response->json('data.0'), 'CustomerGroup');
});

test('BR-PR12 an owner creates a group, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/customer-groups', ['name' => ' مكتبة كبيرة ', 'discount_percent' => 7.5, 'sort_order' => 2])
        ->assertCreated()
        ->assertJsonPath('data.name', 'مكتبة كبيرة')
        ->assertJsonPath('data.discount_percent', '7.50')
        ->assertJsonPath('data.customers_count', 0);

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'customer_group.created', 'entity_id' => $response->json('data.id'), 'actor_id' => $this->me->id]);
});

test('BR-PR12 group validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/customer-groups', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['discount_percent' => 10], 'name'],
    'name too long' => [['name' => str_repeat('م', 101), 'discount_percent' => 10], 'name'],
    'missing percent' => [['name' => 'موزع'], 'discount_percent'],
    'percent above 90 (D89)' => [['name' => 'موزع', 'discount_percent' => 90.01], 'discount_percent'],
    'negative percent' => [['name' => 'موزع', 'discount_percent' => -1], 'discount_percent'],
    'three decimals' => [['name' => 'موزع', 'discount_percent' => '10.125'], 'discount_percent'],
    'sort order out of range' => [['name' => 'موزع', 'discount_percent' => 10, 'sort_order' => 10000], 'sort_order'],
]);

test('BR-PR12 a group name is unique in the store', function (): void {
    $this->inTenant(fn () => CustomerGroup::factory()->create(['name' => 'موزع']));

    $this->tenantJson('POST', '/api/dashboard/customer-groups', ['name' => 'موزع', 'discount_percent' => 10])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_name');
});

test('BR-PR12 PATCH changes only what is sent, audited', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create(['name' => 'موزع', 'discount_percent' => '10.00']));

    $this->tenantJson('PATCH', "/api/dashboard/customer-groups/{$group->id}", ['discount_percent' => '12'])
        ->assertOk()
        ->assertJsonPath('data.name', 'موزع')
        ->assertJsonPath('data.discount_percent', '12.00');

    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_group.updated', 'entity_id' => $group->id]);
});

test('BR-PR12 PATCH validation: status only brings a group back', function (array $body, string $field): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());

    $this->tenantJson('PATCH', "/api/dashboard/customer-groups/{$group->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'archive through patch' => [['status' => 'archived'], 'status'],
    'empty name' => [['name' => ''], 'name'],
    'percent above 90' => [['discount_percent' => 95], 'discount_percent'],
]);

test('D89 archiving keeps the members, PATCH status active brings the group back', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());
    $member = $this->makeCustomer(['customer_group_id' => $group->id]);

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.customers_count', 1);
    $this->tenantJson('PATCH', "/api/dashboard/customer-groups/{$group->id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');

    expect($member->refresh()->customer_group_id)->toBe($group->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_group.archived', 'entity_id' => $group->id]);
});

test('BR-PR12 assigning by ids answers how many moved', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());
    [$a, $b] = [$this->makeCustomer(), $this->makeCustomer()];

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/assign", ['customer_ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertExactJson(['data' => ['assigned' => 2]]);

    expect([$a->refresh()->customer_group_id, $b->refresh()->customer_group_id])->toBe([$group->id, $group->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_group.assigned', 'entity_id' => $group->id, 'actor_id' => $this->me->id]);
});

test('BR-PR12 assigning by the list filter moves every matching customer', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());
    $match = $this->makeCustomer(['name' => 'مكتبة النور']);
    $this->makeCustomer(['name' => 'دار الكتب']);

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/assign", ['filter' => ['q' => 'النور']])
        ->assertOk()
        ->assertJsonPath('data.assigned', 1);

    expect($match->refresh()->customer_group_id)->toBe($group->id);
});

test('BR-PR12 assignment validation', function (array $body, string $field): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/assign", $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'nothing sent' => [[], 'customer_ids'],
    'empty ids' => [['customer_ids' => []], 'customer_ids'],
    'too many ids' => [['customer_ids' => range(1, 501)], 'customer_ids'],
    'not an id' => [['customer_ids' => ['x']], 'customer_ids.0'],
    'ids and filter' => [['customer_ids' => [1], 'filter' => ['q' => 'x']], 'customer_ids'],
    'empty filter (BR-P12)' => [['filter' => ['q' => '']], 'filter.q'],
    'unknown status' => [['filter' => ['status' => 'vip']], 'filter.status'],
    'unknown customer id' => [['customer_ids' => [999999]], 'customer_ids'],
]);

test('D89 nobody is assigned to an archived group', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->archived()->create());
    $customer = $this->makeCustomer();

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/assign", ['customer_ids' => [$customer->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'group_archived');
});

test('BR-T04 another store\'s group is 404 on every group endpoint and stays as it was', function (string $method, string $suffix, array $body): void {
    $foreign = $this->inTenant(fn () => CustomerGroup::factory()->create(['name' => 'غريب']), $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/customer-groups/{$foreign->id}{$suffix}", $body)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('customer_groups', ['id' => $foreign->id, 'name' => 'غريب', 'status' => 'active']);
})->with([
    'update' => ['PATCH', '', ['name' => 'x']],
    'archive' => ['POST', '/archive', []],
    'assign' => ['POST', '/assign', ['customer_ids' => [1]]],
]);

test('BR-PR12 the customer form puts a customer in a group and takes them out', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create(['name' => 'موزع', 'discount_percent' => '15.00']));
    $customer = $this->makeCustomer(['name' => 'مكتبة']);

    $response = $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'مكتبة', 'customer_group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('data.group', ['id' => $group->id, 'name' => 'موزع', 'discount_percent' => '15.00', 'status' => 'active']);
    expectDocumentedShape($response->json('data'), 'DashboardCustomer');
    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'مكتبة', 'customer_group_id' => null])->assertOk()->assertJsonPath('data.group', null);

    $this->assertDatabaseCount('audit_logs', 2);
});

test('BR-PR12 a new customer can start in a group', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());

    $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'مكتبة جديدة', 'phone' => '01012345670', 'customer_group_id' => $group->id])
        ->assertCreated()
        ->assertJsonPath('data.group.id', $group->id);
});

test('D89 the customer form cannot join an archived group or one of another store', function (Closure $groupId, int $status, string $code): void {
    $customer = $this->makeCustomer();

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => $customer->name, 'customer_group_id' => $groupId->call($this)])
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code);

    expect($customer->refresh()->customer_group_id)->toBeNull();
})->with([
    'archived' => [fn (): int => $this->inTenant(fn () => CustomerGroup::factory()->archived()->create())->id, 409, 'group_archived'],
    'another store' => [fn (): int => $this->inTenant(fn () => CustomerGroup::factory()->create(), $this->otherTenant())->id, 422, 'validation_failed'],
]);

test('D82 a sales user edits a customer but cannot change their group', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());
    $customer = $this->makeCustomer(['name' => 'قديم']);

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'جديد', 'customer_group_id' => $group->id])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'جديد'])->assertOk();
    $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'س', 'phone' => '01012345671', 'customer_group_id' => $group->id])->assertForbidden();

    expect($customer->refresh()->customer_group_id)->toBeNull();
});

test('BR-PR12 the customers list filters by group', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create());
    $member = $this->makeCustomer(['customer_group_id' => $group->id]);
    $this->makeCustomer();

    $this->tenantJson('GET', "/api/dashboard/customers?group={$group->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $member->id)
        ->assertJsonPath('data.0.group.id', $group->id);
    $this->tenantJson('GET', '/api/dashboard/customers?group=x')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['group']]]);
});

test('a dashboard session from another tenant host cannot read the groups', function (): void {
    $other = $this->otherTenant();
    $this->app['auth']->forgetGuards();
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);
    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('GET', '/api/dashboard/customer-groups', tenant: $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('group errors never leak exception details when debug is off', function (): void {
    config(['app.debug' => false]);
    $group = $this->inTenant(fn () => CustomerGroup::factory()->archived()->create());

    $this->tenantJson('POST', "/api/dashboard/customer-groups/{$group->id}/assign", ['customer_ids' => [1]])
        ->assertConflict()
        ->assertExactJson(['error' => ['code' => 'group_archived', 'message' => 'هذا المستوى مؤرشف، أعد تفعيله أولًا']]);
});
