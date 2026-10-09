<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Queries\InventoryListQuery;
use App\Modules\Inventory\Queries\InventoryRowQuery;
use App\Modules\Inventory\Queries\LowStockQuery;
use App\Modules\Inventory\Queries\MovementsQuery;
use App\Modules\Tenancy\TenantContext;

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

test('D36 a sku without a stock row is low on stock', function (): void {
    $sku = Sku::factory()->create();

    expect(app(LowStockQuery::class)->builder()->pluck('id')->all())->toBe([$sku->id]);
});

test('D5 the low-stock report leaves out inactive products but keeps coming soon ones', function (): void {
    $comingSoon = Product::factory()->comingSoon()->withSku(onHand: 0)->create();
    Product::factory()->inactive()->withSku(onHand: 0)->create();

    expect(app(LowStockQuery::class)->builder()->pluck('product_id')->all())->toBe([$comingSoon->id]);
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
        ->and($row->inventory?->on_hand)->toBe(5);
});
