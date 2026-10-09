<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    $stock = $this->inTenant(fn () => SkuInventory::factory()->create()->fresh());

    expect(array_keys($stock->toArray()))->toBe(['sku_id', 'tenant_id', 'on_hand', 'reserved', 'created_at', 'updated_at']);
});

test('BR-INV02 available is on hand minus reserved, all integers', function (): void {
    $stock = $this->inTenant(fn () => SkuInventory::factory()->stock(100, 30)->create()->fresh());

    expect($stock->on_hand)->toBe(100)
        ->and($stock->reserved)->toBe(30)
        ->and($stock->available)->toBe(70);
});

test('a stock row is keyed by its sku and belongs to it', function (): void {
    [$sku, $stock] = $this->inTenant(function (): array {
        $sku = Sku::factory()->create();

        return [$sku, SkuInventory::factory()->for($sku)->create()];
    });

    expect($stock->getKey())->toBe($sku->id)
        ->and($this->inTenant(fn () => $stock->sku->is($sku)))->toBeTrue()
        ->and($this->inTenant(fn () => $sku->inventory?->is($stock)))->toBeTrue();
});

test('BR-T03 a stock row created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => SkuInventory::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a stock row without a tenant in context throws', function (): void {
    SkuInventory::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 stock queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => SkuInventory::factory()->create());
    app(TenantContext::class)->clear();

    expect(SkuInventory::query()->count())->toBe(0);
});

test('BR-T03 stock queries in context never see another tenant', function (): void {
    $this->inTenant(fn () => SkuInventory::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => SkuInventory::query()->count()))->toBe(0);
});

test('database checks keep on hand and reserved non-negative and reserved within on hand', function (array $values, string $constraint): void {
    $stock = $this->inTenant(fn () => SkuInventory::factory()->stock(10, 5)->create());

    expect(fn () => DB::table('sku_inventory')->where('sku_id', $stock->sku_id)->update($values))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'BR-INV02 negative on hand' => [['on_hand' => -1, 'reserved' => 0], 'sku_inventory_on_hand_non_negative'],
    'BR-INV02 negative reserved' => [['reserved' => -1], 'sku_inventory_reserved_non_negative'],
    'BR-INV08 reserved above on hand' => [['reserved' => 11], 'sku_inventory_reserved_within_on_hand'],
    'BR-INV08 on hand below reserved' => [['on_hand' => 4], 'sku_inventory_reserved_within_on_hand'],
]);
