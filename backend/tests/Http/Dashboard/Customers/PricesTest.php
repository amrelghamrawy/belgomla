<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Customers\Models\CustomerSkuPrice;

/*
| /api/dashboard/customers/{id}/prices[/{sku}] (BR-PR13, V2 Task 11, D89): the prices negotiated with one customer.
| `pricing.customers` (owner, manager); the permission matrix covers every role.
*/

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل']);
    $this->sku = $this->inTenant(function (): Sku {
        $carton = Unit::factory()->create(['name' => 'كرتونة']);

        return Product::factory()->withSku(['code' => 'ADW-1', 'base_price' => '90', 'selling_unit_id' => $carton->id])->create(['name' => 'الأضواء علوم'])->sku()->sole();
    });
    $this->url = fn (?Sku $sku = null): string => "/api/dashboard/customers/{$this->customer->id}/prices".($sku instanceof Sku ? "/{$sku->id}" : '');
});

test('BR-PR13 an owner sets a negotiated price, audited, and sees it in the list', function (): void {
    $this->freezeSecond();

    $response = $this->tenantJson('PUT', ($this->url)($this->sku), ['unit_price' => '82.5', 'note' => ' اتفاق الموسم '])
        ->assertCreated()
        ->assertExactJson(['data' => [
            'sku_id' => $this->sku->id, 'sku_code' => 'ADW-1', 'product_name' => 'الأضواء علوم', 'unit' => 'كرتونة',
            'base_price' => '90.00', 'unit_price' => '82.50', 'note' => 'اتفاق الموسم', 'updated_at' => now()->toIso8601String(),
        ]]);
    expectDocumentedShape($response->json('data'), 'CustomerSkuPrice');

    $list = $this->tenantJson('GET', ($this->url)())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.unit_price', '82.50')
        ->assertJsonPath('meta.total', 1);
    expectDocumentedShape($list->json('data.0'), 'CustomerSkuPrice');
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer.price_set', 'entity_type' => 'customers', 'entity_id' => $this->customer->id, 'actor_id' => $this->me->id]);
});

test('D89 a negotiated price may be above the base price, and sent again it replaces the old one (200)', function (): void {
    $this->tenantJson('PUT', ($this->url)($this->sku), ['unit_price' => '95'])->assertCreated();

    $this->tenantJson('PUT', ($this->url)($this->sku), ['unit_price' => '120.00'])->assertOk()->assertJsonPath('data.unit_price', '120.00');

    expect($this->inTenant(fn () => CustomerSkuPrice::query()->sole()->unit_price))->toBe('120.00');
});

test('BR-PR13 price validation', function (array $body, string $field): void {
    $this->tenantJson('PUT', ($this->url)($this->sku), $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing price' => [[], 'unit_price'],
    'zero' => [['unit_price' => 0], 'unit_price'],
    'three decimals' => [['unit_price' => '10.125'], 'unit_price'],
    'not a number' => [['unit_price' => 'x'], 'unit_price'],
    'note too long' => [['unit_price' => 10, 'note' => str_repeat('م', 301)], 'note'],
]);

test('BR-PR13 the list searches the product name or the sku code', function (string $q, int $count): void {
    $this->inTenant(fn () => CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id]));

    $this->tenantJson('GET', ($this->url)().'?q='.urlencode($q))->assertOk()->assertJsonCount($count, 'data');
})->with([
    'product name' => ['الأضواء', 1],
    'code' => ['adw', 1],
    'nothing' => ['سلاح', 0],
]);

test('BR-PR13 removing a price answers 204 and a missing one 404', function (): void {
    $this->inTenant(fn () => CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id]));

    $this->tenantJson('DELETE', ($this->url)($this->sku))->assertNoContent();
    $this->tenantJson('DELETE', ($this->url)($this->sku))->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('audit_logs', ['action' => 'customer.price_removed', 'entity_id' => $this->customer->id]);
});

test('BR-T04 another store\'s customer or sku is 404 and nothing changes there', function (string $method, string $which): void {
    $other = $this->otherTenant();
    $foreignCustomer = $this->makeCustomer(tenant: $other);
    $foreignSku = $this->inTenant(fn (): Sku => Product::factory()->withSku()->create()->sku()->sole(), $other);
    $foreignPrice = $this->inTenant(fn () => CustomerSkuPrice::factory()->create(['customer_id' => $foreignCustomer->id, 'sku_id' => $foreignSku->id, 'unit_price' => '50.00']), $other);
    $uri = $which === 'customer'
        ? "/api/dashboard/customers/{$foreignCustomer->id}/prices/{$this->sku->id}"
        : "/api/dashboard/customers/{$this->customer->id}/prices/{$foreignSku->id}";

    $this->tenantJson($method, $uri, ['unit_price' => '10'])->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('customer_sku_prices', ['id' => $foreignPrice->id, 'unit_price' => '50.00']);
})->with([
    'set for a foreign customer' => ['PUT', 'customer'],
    'set a foreign sku' => ['PUT', 'sku'],
    'remove for a foreign customer' => ['DELETE', 'customer'],
    'remove a foreign sku' => ['DELETE', 'sku'],
]);

test('BR-T04 the list of another store\'s customer is 404', function (): void {
    $foreign = $this->makeCustomer(tenant: $this->otherTenant());

    $this->tenantJson('GET', "/api/dashboard/customers/{$foreign->id}/prices")->assertNotFound();
});
