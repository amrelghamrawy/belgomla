<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Queries\InventoryListQuery;
use App\Modules\Inventory\Queries\InventoryRowQuery;
use App\Modules\Inventory\Queries\LowStockQuery;
use App\Modules\Inventory\Queries\MovementsQuery;
use App\Modules\Inventory\Queries\MovementSummaryQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->inTenant(fn () => Sku::factory()->withStock(0)->create(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);
});

test('D36 low stock is available at most moq times the conversion factor, in base units', function (int $onHand, int $reserved, bool $low): void {
    $sku = Sku::factory()->withStock($onHand, $reserved)->create(['moq' => 5, 'conversion_factor' => 20]);

    expect(app(LowStockQuery::class)->builder()->whereKey($sku->id)->exists())->toBe($low);
})->with([
    'exactly one minimum order left' => [120, 20, true],
    'one piece more than a minimum order' => [101, 0, false],
    'nothing left' => [0, 0, true],
]);

test('D36 D91 the threshold is the smallest active unit\'s moq times its factor', function (int $onHand, bool $low): void {
    $sku = Sku::factory()->withStock($onHand)->create(['moq' => 2, 'conversion_factor' => 48]);
    $this->addUnit($sku, ['conversion_factor' => 6, 'moq' => 4]);
    SkuUnit::factory()->archived()->create(['sku_id' => $sku->id, 'conversion_factor' => 1, 'moq' => 1]);

    expect(app(LowStockQuery::class)->builder()->whereKey($sku->id)->exists())->toBe($low);
})->with([
    'exactly four of the smallest unit left' => [24, true],
    'one piece more' => [25, false],
]);

test('D36 a sku without a stock row is low on stock', function (): void {
    $sku = Sku::factory()->create();

    expect(app(LowStockQuery::class)->builder()->pluck('id')->all())->toBe([$sku->id]);
});

test('D93 a bundle without a stock row is never low on stock', function (): void {
    Product::factory()->bundle()->withSku()->create();
    $simple = Product::factory()->withSku()->create();

    expect(app(LowStockQuery::class)->builder()->pluck('product_id')->all())->toBe([$simple->id]);
});

test('D93 the inventory list leaves out bundles, low stock or not', function (bool $lowStock): void {
    Product::factory()->bundle()->withSku(['code' => 'SET-1'])->create();
    Product::factory()->withSku(['code' => 'A-1'])->create();

    $page = app(InventoryListQuery::class)->paginate(null, $lowStock, null, 24);

    expect(collect($page->items())->pluck('code')->all())->toBe(['A-1']);
})->with([
    'every sku' => [false],
    'D36 low stock' => [true],
]);

test('D5 the low-stock report leaves out inactive products but keeps coming soon ones', function (): void {
    $comingSoon = Product::factory()->comingSoon()->withSku(onHand: 0)->create();
    Product::factory()->inactive()->withSku(onHand: 0)->create();

    expect(app(LowStockQuery::class)->builder()->pluck('product_id')->all())->toBe([$comingSoon->id]);
});

test('BR-P14 the low-stock report leaves out an inactive variant', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أحمر'], stock: ['أزرق' => 0, 'أحمر' => 0]);
    Sku::query()->where('code', 'PEN-2')->update(['status' => 'inactive']);

    expect(app(LowStockQuery::class)->builder()->pluck('code')->all())->toBe(['PEN-1']);
});

test('the inventory list shows every sku of the tenant with product and stock loaded, by code', function (): void {
    $b = Product::factory()->inactive()->withSku(['code' => 'B-1'], onHand: 3)->create();
    $a = Product::factory()->withSku(['code' => 'A-1'], onHand: 9)->create();

    $page = app(InventoryListQuery::class)->paginate(null, false, null, 24);

    expect($page->total())->toBe(2)
        ->and(collect($page->items())->pluck('product_id')->all())->toBe([$a->id, $b->id])
        ->and($page->items()[0]->relationLoaded('product'))->toBeTrue()
        ->and($page->items()[0]->relationLoaded('inventory'))->toBeTrue();
});

test('the inventory list searches like the catalog and filters low stock', function (?string $q, bool $lowStock, string $expected): void {
    $codes = [
        'science' => Product::factory()->withSku(['code' => 'SCI-1', 'moq' => 1], onHand: 50)->create(['name' => 'علوم'])->id,
        'math' => Product::factory()->withSku(['code' => 'MAT-1', 'moq' => 1], onHand: 1)->create(['name' => 'رياضيات'])->id,
    ];

    $page = app(InventoryListQuery::class)->paginate($q, $lowStock, null, 24);

    expect(collect($page->items())->pluck('product_id')->all())->toBe([$codes[$expected]]);
})->with([
    'D32 by name' => ['علوم', false, 'science'],
    'by sku code' => ['mat-1', false, 'math'],
    'D36 low stock' => [null, true, 'math'],
]);

test('the inventory list sorts by available stock, a missing row counting as zero', function (string $direction, array $order): void {
    $ids = [
        'none' => Sku::factory()->create()->id,
        'some' => Sku::factory()->withStock(10, 5)->create()->id,
        'many' => Sku::factory()->withStock(40)->create()->id,
    ];

    $page = app(InventoryListQuery::class)->paginate(null, false, ['available', $direction], 24);

    expect(collect($page->items())->pluck('id')->all())->toBe(array_map(fn (string $key): int => $ids[$key], $order));
})->with([
    'ascending' => ['asc', ['none', 'some', 'many']],
    'descending' => ['desc', ['many', 'some', 'none']],
]);

test('BR-INV06 movements of a sku, newest first, paginated', function (): void {
    $sku = Sku::factory()->create();
    $older = InventoryMovement::factory()->for($sku)->create();
    $newer = InventoryMovement::factory()->for($sku)->adjustment(-10, 90)->create();
    InventoryMovement::factory()->create();

    $page = app(MovementsQuery::class)->paginate($sku, 24);

    expect(collect($page->items())->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('the inventory row loads the product and stock of the sku', function (): void {
    $sku = Sku::factory()->withStock(5)->create();

    $row = app(InventoryRowQuery::class)->get(Sku::query()->findOrFail($sku->id));

    expect($row->relationLoaded('product'))->toBeTrue()
        ->and($row->relationLoaded('smallestUnit'))->toBeTrue()
        ->and($row->inventory?->on_hand)->toBe(5);
});

test('V2 Task 16 BR-INV06 the movement summary sums the signed base units of the range per sku and type', function (): void {
    $sku = Sku::factory()->withStock(0)->create();
    InventoryMovement::factory()->create(['sku_id' => $sku->id, 'type' => 'opening', 'quantity' => 100, 'created_at' => '2026-10-09 10:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $sku->id, 'type' => 'sale', 'quantity' => -6, 'on_hand_after' => 94, 'created_at' => '2026-10-09 11:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $sku->id, 'type' => 'sale', 'quantity' => -4, 'on_hand_after' => 90, 'created_at' => '2026-10-10 11:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $sku->id, 'type' => 'return', 'quantity' => 2, 'on_hand_after' => 92, 'created_at' => '2026-10-12 11:00:00']);

    $rows = app(MovementSummaryQuery::class)->bySku(CarbonImmutable::parse('2026-10-09 00:00:00'), CarbonImmutable::parse('2026-10-10 23:59:59'))->get();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows->sole())->toEqual(['sku_id' => $sku->id, 'opening' => 100, 'adjustment' => 0, 'sale' => -10, 'return' => 0, 'receive' => 0]);
});
