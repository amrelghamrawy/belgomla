<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    [$this->counted, $this->held] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(onHand: 10)->create()->sku()->sole(),
        Product::factory()->withSku(onHand: 30, reserved: 20)->create()->sku()->sole(),
    ]);
    $this->foreign = $this->inTenant(fn () => Product::factory()->withSku(onHand: 7)->create()->sku()->sole(), $this->otherTenant());
});

test('BR-INV08 bulk adjust applies the valid counts and reports skipped skus, in one audit batch', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/inventory/bulk-adjust', [
        ['sku_id' => $this->counted->id, 'new_on_hand' => 25, 'reason' => 'جرد'],
        ['sku_id' => $this->held->id, 'new_on_hand' => 5, 'reason' => 'جرد'],
        ['sku_id' => $this->foreign->id, 'new_on_hand' => 0, 'reason' => 'جرد'],
    ])
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['applied', 'skipped', 'batch_id']])
        ->assertJsonPath('data.applied', 1)
        ->assertJsonPath('data.skipped', [
            ['sku_id' => $this->held->id, 'reason' => 'below_reserved', 'reserved' => 20, 'min_allowed' => 20],
            ['sku_id' => $this->foreign->id, 'reason' => 'not_found'],
        ]);

    $logs = $this->inTenant(fn () => AuditLog::query()->get());

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->counted->id, 'on_hand' => 25]);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->held->id, 'on_hand' => 30]);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->foreign->id, 'on_hand' => 7]);
    $this->assertDatabaseHas('inventory_movements', ['sku_id' => $this->counted->id, 'quantity' => 15, 'actor_id' => $this->me->id]);
    expect($logs->pluck('batch_id')->unique()->all())->toBe([$response->json('data.batch_id')])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id])
        ->and($logs->pluck('action')->sort()->values()->all())->toBe(['inventory.bulk_adjusted', 'stock.adjusted']);
});

test('bulk adjust validation', function (Closure $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/inventory/bulk-adjust', $body($this->counted->id))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'empty list' => [fn (int $id): array => [], 'items'],
    'an object instead of a list' => [fn (int $id): array => ['sku_id' => $id, 'new_on_hand' => 1, 'reason' => 'جرد'], 'items'],
    'more than 500 items' => [fn (int $id): array => array_fill(0, 501, ['sku_id' => $id, 'new_on_hand' => 1, 'reason' => 'جرد']), 'items'],
    'missing sku id' => [fn (int $id): array => [['new_on_hand' => 1, 'reason' => 'جرد']], 'items.0.sku_id'],
    'the same sku twice' => [fn (int $id): array => [['sku_id' => $id, 'new_on_hand' => 1, 'reason' => 'جرد'], ['sku_id' => $id, 'new_on_hand' => 2, 'reason' => 'جرد']], 'items.1.sku_id'],
    'BR-INV02 negative on hand' => [fn (int $id): array => [['sku_id' => $id, 'new_on_hand' => -1, 'reason' => 'جرد']], 'items.0.new_on_hand'],
    'BR-INV06 missing reason' => [fn (int $id): array => [['sku_id' => $id, 'new_on_hand' => 1]], 'items.0.reason'],
]);
