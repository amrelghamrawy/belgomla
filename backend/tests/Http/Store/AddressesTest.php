<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CustomerAddress;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer($this->makeCustomer(['phone' => '+201012345678']));
});

function addressBody(array $overrides = []): array
{
    return [...['label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'address_line' => '12 شارع عباس العقاد'], ...$overrides];
}

function addressOf(App\Modules\Customers\Models\Customer $customer, array $attributes = []): CustomerAddress
{
    return app(App\Modules\Tenancy\TenantContext::class)->runAs(
        App\Modules\Tenancy\Models\Tenant::query()->findOrFail($customer->tenant_id),
        fn (): CustomerAddress => CustomerAddress::factory()->create(['customer_id' => $customer->id, ...$attributes]),
    );
}

test('BR-CU03 the first address is created as the default with the customer phone', function (): void {
    $this->tenantJson('POST', '/api/store/addresses', addressBody(['landmark' => 'بجوار البنك']))
        ->assertCreated()
        ->assertExactJsonStructure(['data' => ['id', 'label', 'governorate', 'city', 'address_line', 'landmark', 'phone', 'is_default']])
        ->assertJsonPath('data.label', 'المحل')
        ->assertJsonPath('data.landmark', 'بجوار البنك')
        ->assertJsonPath('data.phone', '+201012345678')
        ->assertJsonPath('data.is_default', true);
});

test('BR-CU03 a second address with is_default takes the default over', function (): void {
    $first = addressOf($this->customer, ['is_default' => true]);

    $this->tenantJson('POST', '/api/store/addresses', addressBody(['label' => 'المخزن', 'phone' => '01112345678', 'is_default' => true]))
        ->assertCreated()
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.phone', '+201112345678');

    expect($this->inTenant(fn () => $first->refresh()->is_default))->toBeFalse();
});

test('BR-CU03 a customer lists only their own active addresses, default first', function (): void {
    $other = addressOf($this->customer, ['label' => 'المخزن']);
    $default = addressOf($this->customer, ['label' => 'المحل', 'is_default' => true]);
    addressOf($this->customer, ['status' => AddressStatus::Archived]);
    addressOf($this->makeCustomer());

    $this->tenantJson('GET', '/api/store/addresses')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $default->id)
        ->assertJsonPath('data.1.id', $other->id);
});

test('BR-CU03 an address is replaced on update', function (): void {
    $address = addressOf($this->customer, ['is_default' => true]);

    $this->tenantJson('PUT', "/api/store/addresses/{$address->id}", addressBody(['city' => 'المعادي']))
        ->assertOk()
        ->assertJsonPath('data.city', 'المعادي')
        ->assertJsonPath('data.landmark', null)
        ->assertJsonPath('data.is_default', true);
});

test('BR-CU03 deleting archives the address and the next one becomes the default', function (): void {
    $default = addressOf($this->customer, ['is_default' => true]);
    $next = addressOf($this->customer);

    $this->tenantJson('DELETE', "/api/store/addresses/{$default->id}")->assertNoContent();

    $this->assertDatabaseHas('customer_addresses', ['id' => $default->id, 'status' => 'archived', 'is_default' => false]);
    $this->assertDatabaseHas('customer_addresses', ['id' => $next->id, 'is_default' => true]);
});

test('BR-CU03 another customer\'s address is 404 and unchanged', function (string $method): void {
    $foreign = addressOf($this->makeCustomer(), ['label' => 'لغيري']);

    $this->tenantJson($method, "/api/store/addresses/{$foreign->id}", addressBody())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id, 'label' => 'لغيري', 'status' => 'active']);
})->with(['PUT', 'DELETE']);

test('an archived address cannot be edited again', function (): void {
    $archived = addressOf($this->customer, ['status' => AddressStatus::Archived]);

    $this->tenantJson('PUT', "/api/store/addresses/{$archived->id}", addressBody())->assertNotFound();
});

test('BR-T04 an address id of another tenant is 404 and unchanged there', function (): void {
    $foreign = addressOf($this->makeCustomer([], $this->otherTenant()), ['label' => 'هناك']);

    $this->tenantJson('PUT', "/api/store/addresses/{$foreign->id}", addressBody())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id, 'label' => 'هناك']);
});

test('address validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/addresses', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing label' => [addressBody(['label' => null]), 'label'],
    'label too long' => [addressBody(['label' => str_repeat('م', 51)]), 'label'],
    'missing governorate' => [addressBody(['governorate' => null]), 'governorate'],
    'governorate too long' => [addressBody(['governorate' => str_repeat('م', 51)]), 'governorate'],
    'missing city' => [addressBody(['city' => null]), 'city'],
    'city too long' => [addressBody(['city' => str_repeat('م', 81)]), 'city'],
    'missing address line' => [addressBody(['address_line' => null]), 'address_line'],
    'address line too long' => [addressBody(['address_line' => str_repeat('م', 301)]), 'address_line'],
    'landmark too long' => [addressBody(['landmark' => str_repeat('م', 201)]), 'landmark'],
    'invalid phone' => [addressBody(['phone' => '0223456789']), 'phone'],
    'is_default not boolean' => [addressBody(['is_default' => 'maybe']), 'is_default'],
]);

test('BR-CU05 a customer who is not active cannot manage addresses', function (): void {
    $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save();

    $this->tenantJson('GET', '/api/store/addresses')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'blocked');
});
