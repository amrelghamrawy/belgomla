<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;

const INVENTORY_ROW_KEYS = ['sku_id', 'product_id', 'code', 'name', 'status', 'on_hand', 'reserved', 'available', 'min_allowed', 'moq', 'conversion_factor', 'low_stock'];

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-INV02 the inventory lists every sku of the tenant with its stock in base units, by code, paginated with meta', function (): void {
    [$science, $math] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(['code' => 'SCI-1', 'moq' => 5, 'conversion_factor' => 20], onHand: 140, reserved: 30)->create(['name' => 'علوم']),
        Product::factory()->inactive()->withSku(['code' => 'MAT-1'])->create(['name' => 'رياضيات']),
    ]);
    $this->inTenant(fn () => Product::factory()->withSku(onHand: 5)->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/inventory')
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => INVENTORY_ROW_KEYS],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.product_id', $math->id)
        ->assertJsonPath('data.0.status', 'inactive')
        ->assertJsonPath('data.0.on_hand', 0)
        ->assertJsonPath('data.1', [
            'sku_id' => $this->inTenant(fn () => $science->sku()->sole()->id), 'product_id' => $science->id, 'code' => 'SCI-1', 'name' => 'علوم', 'status' => 'active',
            'on_hand' => 140, 'reserved' => 30, 'available' => 110, 'min_allowed' => 30, 'moq' => 5, 'conversion_factor' => 20, 'low_stock' => false,
        ]);
});

test('the inventory list searches, filters low stock and sorts by available', function (string $query, array $expected): void {
    $ids = $this->inTenant(fn (): array => [
        'science' => Product::factory()->withSku(['code' => 'SCI-1'], onHand: 50)->create(['name' => 'علوم'])->sku->id,
        'math' => Product::factory()->withSku(['code' => 'MAT-1'], onHand: 1)->create(['name' => 'رياضيات'])->sku->id,
    ]);

    $listed = $this->tenantJson('GET', "/api/dashboard/inventory?{$query}")
        ->assertOk()
        ->json('data.*.sku_id');

    expect($listed)->toBe(array_map(fn (string $key): int => $ids[$key], $expected));
})->with([
    'D32 search by name' => ['q=علوم', ['science']],
    'search by sku code' => ['q=sci-1', ['science']],
    'D36 low stock' => ['low_stock=1', ['math']],
    'available descending' => ['sort=-available', ['science', 'math']],
    'code descending' => ['sort=-code', ['science', 'math']],
]);

test('inventory list validation', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/inventory?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown sort' => ['sort=name', 'sort'],
    'low stock not a boolean' => ['low_stock=maybe', 'low_stock'],
    'per page over 100' => ['per_page=101', 'per_page'],
    'search too long' => ['q='.str_repeat('a', 101), 'q'],
]);

test('BR-INV08 one sku shows its stock and the minimum on hand an adjustment may set', function (): void {
    $sku = $this->inTenant(fn () => Product::factory()->withSku(['code' => 'SCI-1'], onHand: 100, reserved: 80)->create()->sku()->sole());

    $this->tenantJson('GET', "/api/dashboard/inventory/{$sku->id}")
        ->assertOk()
        ->assertExactJsonStructure(['data' => INVENTORY_ROW_KEYS])
        ->assertJsonPath('data.on_hand', 100)
        ->assertJsonPath('data.reserved', 80)
        ->assertJsonPath('data.available', 20)
        ->assertJsonPath('data.min_allowed', 80);
});

test('BR-T04 a sku of another tenant is 404 and untouched', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku(onHand: 7)->create()->sku()->sole(), $this->otherTenant());

    $this->tenantJson('GET', "/api/dashboard/inventory/{$foreign->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $foreign->id, 'on_hand' => 7]);
});
