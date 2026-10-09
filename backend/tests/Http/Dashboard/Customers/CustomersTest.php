<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

function dashboardAddress(array $overrides = []): array
{
    return [...['label' => 'المحل', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => '5 شارع التحرير'], ...$overrides];
}

test('the customer list shows every customer, newest first, with the outstanding amount', function (): void {
    $old = $this->makeCustomer(['name' => 'قديم']);
    $new = $this->makeCustomer(['name' => 'جديد', 'status' => CustomerStatus::Invited, 'password' => null]);

    $this->tenantJson('GET', '/api/dashboard/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.status', 'invited')
        ->assertJsonPath('data.0.outstanding_amount', '0.00')
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('meta.total', 2)
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'phone', 'status', 'status_label', 'phone_verified_at', 'created_at', 'outstanding_amount', 'created_by', 'approved_by', 'blocked_at', 'blocked_reason']], 'links', 'meta']);
});

test('D5 the outstanding amount comes from the OutstandingBalances contract', function (): void {
    $customer = $this->makeCustomer();
    app()->instance(OutstandingBalances::class, new readonly class implements OutstandingBalances
    {
        public function forCustomers(array $customerIds): array
        {
            return array_fill_keys($customerIds, '1250.50');
        }
    });

    $this->tenantJson('GET', '/api/dashboard/customers')->assertJsonPath('data.0.outstanding_amount', '1250.50');
    $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}")->assertJsonPath('data.outstanding_amount', '1250.50');
});

test('the list searches the name or the phone digits', function (string $q): void {
    $match = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->makeCustomer(['name' => 'مكتبة النجاح', 'phone' => '+201198765432']);

    $this->tenantJson('GET', '/api/dashboard/customers?q='.urlencode($q))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
})->with(['part of the name' => ['الأمل'], 'phone as typed' => ['01012345678'], 'end of the phone' => ['345678']]);

test('the list filters by status', function (): void {
    $this->makeCustomer();
    $pending = $this->makeCustomer(['status' => CustomerStatus::PendingApproval]);

    $this->tenantJson('GET', '/api/dashboard/customers?status=pending_approval')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id);
});

test('BR-CU02 the list never shows another tenant\'s customers', function (): void {
    $this->makeCustomer([], $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/customers')->assertJsonCount(0, 'data');
});

test('list validation', function (string $query, string $field): void {
    $this->tenantJson('GET', '/api/dashboard/customers?'.$query)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown status' => ['status=vip', 'status'],
    'search too long' => ['q='.str_repeat('a', 101), 'q'],
    'per page above 100' => ['per_page=101', 'per_page'],
]);

test('BR-CU08 the merchant creates an invited customer with addresses, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/customers', [
        'name' => 'مكتبة الأمل',
        'phone' => '01012345678',
        'addresses' => [dashboardAddress(), dashboardAddress(['label' => 'المخزن', 'is_default' => true])],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.phone', '+201012345678')
        ->assertJsonPath('data.created_by', $this->me->id)
        ->assertJsonPath('data.outstanding_amount', '0.00')
        ->assertJsonCount(2, 'data.addresses')
        ->assertJsonPath('data.addresses.0.label', 'المخزن')
        ->assertJsonPath('data.addresses.0.is_default', true)
        ->assertJsonPath('data.addresses.0.phone', '+201012345678');

    $audit = $this->inTenant(fn () => AuditLog::query()->sole());
    expect($audit->only(['action', 'entity_type', 'entity_id', 'actor_id', 'old_values']))->toBe(['action' => 'customer.created', 'entity_type' => 'customers', 'entity_id' => $response->json('data.id'), 'actor_id' => $this->me->id, 'old_values' => null])
        ->and($audit->new_values)->toBe(['name' => 'مكتبة الأمل', 'phone' => '+201012345678', 'status' => 'invited']);
});

test('BR-CU04 a phone already used in the tenant cannot be created again', function (): void {
    $this->makeCustomer(['phone' => '+201012345678']);

    $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'مكرر', 'phone' => '+20 10 1234 5678'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'phone_taken');

    $this->assertDatabaseCount('audit_logs', 0);
});

test('create validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/customers', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['phone' => '01012345678'], 'name'],
    'name too long' => [['name' => str_repeat('م', 151), 'phone' => '01012345678'], 'name'],
    'missing phone' => [['name' => 'س'], 'phone'],
    'invalid phone' => [['name' => 'س', 'phone' => '12345'], 'phone'],
    'addresses not a list' => [['name' => 'س', 'phone' => '01012345678', 'addresses' => 'x'], 'addresses'],
    'too many addresses' => [['name' => 'س', 'phone' => '01012345678', 'addresses' => array_fill(0, 11, dashboardAddress())], 'addresses'],
    'address without a city' => [['name' => 'س', 'phone' => '01012345678', 'addresses' => [dashboardAddress(['city' => null])]], 'addresses.0.city'],
    'address with an invalid phone' => [['name' => 'س', 'phone' => '01012345678', 'addresses' => [dashboardAddress(['phone' => '1'])]], 'addresses.0.phone'],
]);

test('the customer page shows the profile, active addresses and recent orders', function (): void {
    $customer = $this->makeCustomer();
    $this->inTenant(fn () => CustomerAddress::factory()->for($customer)->default()->create());
    $this->inTenant(fn () => CustomerAddress::factory()->for($customer)->archived()->create());
    app()->instance(RecentOrders::class, new readonly class implements RecentOrders
    {
        public function forCustomer(int $customerId, int $limit = 5): array
        {
            return [['id' => 9, 'number' => 1024, 'status' => 'pending', 'total' => '540.00', 'placed_at' => '2026-10-09T10:00:00+00:00']];
        }
    });

    $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id)
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.recent_orders.0.number', 1024);
});

test('a customer without orders has no recent orders', function (): void {
    $customer = $this->makeCustomer();

    $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}")->assertJsonPath('data.recent_orders', []);
});

test('D54 the merchant renames a customer and syncs the addresses, audited', function (): void {
    $customer = $this->makeCustomer(['name' => 'قديم']);
    $kept = $this->inTenant(fn () => CustomerAddress::factory()->for($customer)->default()->create(['label' => 'المحل']));
    $dropped = $this->inTenant(fn () => CustomerAddress::factory()->for($customer)->create(['label' => 'قديم']));

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", [
        'name' => 'جديد',
        'addresses' => [dashboardAddress(['id' => $kept->id, 'label' => 'المحل الرئيسي']), dashboardAddress(['label' => 'فرع'])],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'جديد')
        ->assertJsonCount(2, 'data.addresses')
        ->assertJsonPath('data.addresses.0.label', 'المحل الرئيسي');

    $this->assertDatabaseHas('customer_addresses', ['id' => $dropped->id, 'status' => AddressStatus::Archived->value]);
    $audit = $this->inTenant(fn () => AuditLog::query()->sole());
    expect($audit->action)->toBe('customer.updated')
        ->and($audit->old_values['name'] ?? null)->toBe('قديم')
        ->and($audit->new_values['name'] ?? null)->toBe('جديد');
});

test('D54 without addresses the update keeps them', function (): void {
    $customer = $this->makeCustomer();
    $this->inTenant(fn () => CustomerAddress::factory()->for($customer)->default()->create());

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'جديد'])
        ->assertOk()
        ->assertJsonCount(1, 'data.addresses');
});

test('D54 an address id of another customer is 404 and nothing changes', function (): void {
    $customer = $this->makeCustomer(['name' => 'قديم']);
    $foreign = $this->inTenant(fn () => CustomerAddress::factory()->create(['label' => 'لغيره']));

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", ['name' => 'جديد', 'addresses' => [dashboardAddress(['id' => $foreign->id])]])
        ->assertNotFound();

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'قديم']);
    $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id, 'label' => 'لغيره']);
});

test('update validation', function (array $body, string $field): void {
    $customer = $this->makeCustomer();

    $this->tenantJson('PUT', "/api/dashboard/customers/{$customer->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [[], 'name'],
    'address id not an integer' => [['name' => 'س', 'addresses' => [dashboardAddress(['id' => 'x'])]], 'addresses.0.id'],
]);

test('BR-T04 BR-CU02 a customer of another tenant is 404 on every customer endpoint and unchanged', function (string $method, string $suffix, array $body): void {
    $foreign = $this->makeCustomer(['name' => 'هناك', 'status' => CustomerStatus::PendingApproval], $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/customers/{$foreign->id}{$suffix}", $body)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'name' => 'هناك', 'status' => 'pending_approval']);
})->with([
    'show' => ['GET', '', []],
    'update' => ['PUT', '', ['name' => 'هنا']],
    'invite' => ['POST', '/invite', []],
    'approve' => ['POST', '/approve', []],
    'block' => ['POST', '/block', ['reason' => 'x']],
    'unblock' => ['POST', '/unblock', []],
]);

test('a customer created here is visible only here', function (): void {
    $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'س', 'phone' => '01012345678'])->assertCreated();

    expect(Customer::withoutTenantScope()->sole()->tenant_id)->toBe($this->tenant->id);
});

test('the customer page lists the latest orders of the customer (Orders fills RecentOrders)', function (): void {
    $customer = $this->makeCustomer();
    $order = $this->placeOrder($customer, [$this->orderableSku()->id => 5]);

    $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.recent_orders', [[
            'id' => $order->id, 'number' => $order->number, 'status' => 'pending', 'total' => '450.00', 'placed_at' => $order->placed_at->toIso8601String(),
        ]]);
});
