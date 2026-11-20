<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\Enums\TenantUserRole;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->sku = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(48)->create());
    $this->carton = $this->addUnit($this->sku, ['conversion_factor' => 12]);
    $this->setCost = fn (array $body, ?Sku $sku = null) => $this->tenantJson('PUT', '/api/dashboard/inventory/'.($sku ?? $this->sku)->id.'/cost', $body);
});

test('D96 d the merchant sets the cost per carton and the average is stored per base unit, without a movement', function (): void {
    ($this->setCost)(['unit_cost' => '57.00', 'sku_unit_id' => $this->carton->id])
        ->assertOk()
        ->assertJsonPath('data.on_hand', 48)
        ->assertJsonPath('data.avg_cost', '4.7500'); // 57 ÷ 12

    $this->assertDatabaseCount('inventory_movements', 0);
});

test('BR-L setting the cost is audited with the old and new average', function (): void {
    ($this->setCost)(['unit_cost' => '4.75'])->assertOk();

    $audit = $this->inTenant(fn () => AuditLog::query()->sole());

    expect($audit->only(['action', 'entity_id', 'actor_id', 'old_values']))->toBe(['action' => 'stock.cost_set', 'entity_id' => $this->sku->id, 'actor_id' => $this->me->id, 'old_values' => ['avg_cost' => null]])
        ->and($audit->new_values)->toEqual(['avg_cost' => '4.7500', 'unit_cost' => '4.75', 'sku_unit_id' => null]);
});

test('D96 d setting the cost it already has is not audited', function (): void {
    ($this->setCost)(['unit_cost' => '4.75'])->assertOk();
    ($this->setCost)(['unit_cost' => '57', 'sku_unit_id' => $this->carton->id])->assertOk()->assertJsonPath('data.avg_cost', '4.7500');

    $this->assertDatabaseCount('audit_logs', 1);
});

test('D96 a a unit of another sku is refused on sku_unit_id', function (): void {
    $other = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->create());

    ($this->setCost)(['unit_cost' => '10.00', 'sku_unit_id' => $this->defaultUnitId($other)])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['sku_unit_id']]]);

    expect($this->inTenant(fn () => SkuInventory::query()->findOrFail($this->sku->id)->avg_cost))->toBeNull();
});

test('D93 b a bundle has no stock to cost', function (): void {
    $bundle = $this->bundleSku($this->gradeSet([$this->sku->id => 1]));

    ($this->setCost)(['unit_cost' => '10.00'], $bundle)
        ->assertConflict()
        ->assertJsonPath('error.code', 'is_bundle');
});

test('cost validation', function (array $body, string $field): void {
    ($this->setCost)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing cost' => [[], 'unit_cost'],
    'negative cost' => [['unit_cost' => '-0.01'], 'unit_cost'],
    'cost with three decimals' => [['unit_cost' => '0.001'], 'unit_cost'],
    'unit not an id' => [['unit_cost' => '1.00', 'sku_unit_id' => 'x'], 'sku_unit_id'],
]);

test('BR-T04 setting the cost of a sku of another tenant is 404 and changes nothing there', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(7, avgCost: '1.0000')->create(), $other);

    ($this->setCost)(['unit_cost' => '9.00'], $foreign)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect($this->inTenant(fn () => SkuInventory::query()->findOrFail($foreign->id)->avg_cost, $other))->toBe('1.0000');
});

test('D96 g the average shows on the inventory row only to users who adjust stock or view reports', function (TenantUserRole $role, bool $sees): void {
    $this->inTenant(fn () => SkuInventory::query()->whereKey($this->sku->id)->update(['avg_cost' => '4.7500']));
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role, 'email' => "{$role->value}@alnoor.test"]));

    $response = $this->tenantJson('GET', "/api/dashboard/inventory/{$this->sku->id}")->assertOk();

    expect(array_key_exists('avg_cost', $response->json('data')))->toBe($sees);
})->with([
    'owner' => [TenantUserRole::Owner, true],
    'manager' => [TenantUserRole::Manager, true],
    'warehouse' => [TenantUserRole::Warehouse, true],
    'accountant' => [TenantUserRole::Accountant, true],
    'sales' => [TenantUserRole::Sales, false],
]);
