<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Pricing\Models\SkuPriceTier;
use Illuminate\Support\Facades\DB;

function bulkPricingSkuId(Product $product): int
{
    return (int) DB::table('skus')->where('product_id', $product->id)->value('id');
}

/** D91: the product's default unit, read past the tenant scope (the test reads it outside any tenant context). */
function bulkPricingUnitId(Product $product): int
{
    return (int) DB::table('sku_units')->where('sku_id', bulkPricingSkuId($product))->where('is_default', true)->value('id');
}

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();

    [$this->adwaa, $this->other] = $this->inTenant(function (): array {
        $publisher = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'publisher']);

        return [
            ProductFieldOption::factory()->for($publisher, 'definition')->create(['label' => 'الأضواء']),
            ProductFieldOption::factory()->for($publisher, 'definition')->create(['label' => 'سلاح التلميذ']),
        ];
    });

    [$this->science, $this->math, $this->noCover, $this->otherBook] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(['cover_price' => '120.00', 'base_price' => '110.00'])->create(['name' => 'علوم', 'custom_fields' => ['publisher' => $this->adwaa->id]]),
        Product::factory()->withSku(['cover_price' => '80.00', 'base_price' => '80.00'])->create(['name' => 'رياضيات', 'custom_fields' => ['publisher' => $this->adwaa->id]]),
        Product::factory()->withSku(['code' => 'ADW-NC', 'cover_price' => null, 'base_price' => '50.00'])->create(['name' => 'دراسات', 'custom_fields' => ['publisher' => $this->adwaa->id]]),
        Product::factory()->withSku(['cover_price' => '100.00', 'base_price' => '100.00'])->create(['name' => 'عربي', 'custom_fields' => ['publisher' => $this->other->id]]),
    ]);

    $this->body = fn (array $overrides = []): array => [
        'filter' => ['f' => ['publisher' => $this->adwaa->id]],
        'base_price_percent' => 25,
        'tiers' => [['min_quantity' => 10, 'discount_percent' => 30], ['min_quantity' => 50, 'discount_percent' => 35]],
        ...$overrides,
    ];
});

test('BR-PR11 the preview counts the skus a publisher filter would reprice and lists the skipped ones, writing nothing', function (): void {
    $this->tenantJson('POST', '/api/dashboard/pricing/bulk/preview', ($this->body)())
        ->assertOk()
        ->assertExactJson(['data' => [
            'affected' => 2,
            'skipped_no_cover_price' => [[
                'product_id' => $this->noCover->id, 'sku_id' => bulkPricingSkuId($this->noCover), 'sku_unit_id' => bulkPricingUnitId($this->noCover),
                'code' => 'ADW-NC', 'name' => 'دراسات', 'unit' => DB::table('units')->join('sku_units', 'sku_units.unit_id', '=', 'units.id')->where('sku_units.id', bulkPricingUnitId($this->noCover))->value('units.name'),
            ]],
        ]]);

    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->science), 'base_price' => '110.00']);
    expect($this->inTenant(fn () => AuditLog::query()->count()))->toBe(0)
        ->and($this->inTenant(fn () => SkuPriceTier::query()->count()))->toBe(0);
});

test('BR-PR11 apply prices the publisher off each cover price in one audit batch', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['moq' => 5]))
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['affected', 'skipped_no_cover_price' => ['*' => ['product_id', 'sku_id', 'sku_unit_id', 'code', 'name', 'unit']], 'batch_id']])
        ->assertJsonPath('data.affected', 2)
        ->assertJsonPath('data.skipped_no_cover_price.0.sku_unit_id', bulkPricingUnitId($this->noCover));

    $logs = $this->inTenant(fn () => AuditLog::query()->get());

    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->science), 'base_price' => '90.00', 'base_price_percent' => '25.00', 'moq' => 5]);
    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->math), 'base_price' => '60.00']);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_unit_id' => bulkPricingUnitId($this->science), 'min_quantity' => 50, 'unit_price' => '78.00', 'discount_percent' => '35.00']);
    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->noCover), 'base_price' => '50.00']);
    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->otherBook), 'base_price' => '100.00']);
    expect($logs->pluck('batch_id')->unique()->all())->toBe([$response->json('data.batch_id')])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id])
        ->and($logs->where('action', 'pricing.bulk_applied'))->toHaveCount(1)
        ->and($logs->where('action', 'sku.price_changed'))->toHaveCount(2);
});

test('BR-PR05 duplicate thresholds fail preview and apply alike with invalid_tiers', function (string $uri): void {
    $this->tenantJson('POST', $uri, ($this->body)(['tiers' => [['min_quantity' => 10, 'discount_percent' => 30], ['min_quantity' => 10, 'discount_percent' => 35]]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_tiers');

    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->science), 'base_price' => '110.00']);
})->with(['preview' => ['/api/dashboard/pricing/bulk/preview'], 'apply' => ['/api/dashboard/pricing/bulk']]);

test('D91 a store unit filter reprices only the units sold in it', function (): void {
    $carton = $this->addUnit($this->inTenant(fn () => $this->science->sku()->sole()), ['cover_price' => '1440.00', 'base_price' => '1300.00']);

    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['unit_id' => $carton->unit_id, 'tiers' => []]))
        ->assertOk()
        ->assertJsonPath('data.affected', 1);

    $this->assertDatabaseHas('sku_units', ['id' => $carton->id, 'base_price' => '1080.00']);
    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($this->science), 'base_price' => '110.00']);
});

test('BR-P12 an empty filter is refused so a mistake cannot reprice the whole catalog', function (): void {
    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['filter' => ['q' => '']]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['filter']]]);
});

test('bulk pricing validation', function (array $overrides, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing filter' => [['filter' => null], 'filter'],
    'filter category not an id' => [['filter' => ['category' => 'books']], 'filter.category'],
    'filter status unknown' => [['filter' => ['status' => 'gone']], 'filter.status'],
    'BR-PR11 missing base percent' => [['base_price_percent' => null], 'base_price_percent'],
    'BR-PR10 base percent of 100' => [['base_price_percent' => 100], 'base_price_percent'],
    'tiers not a list' => [['tiers' => 'x'], 'tiers'],
    'BR-PR03 tier without a minimum' => [['tiers' => [['discount_percent' => 30]]], 'tiers.0.min_quantity'],
    'BR-PR11 tier without a percent' => [['tiers' => [['min_quantity' => 10]]], 'tiers.0.discount_percent'],
    'BR-PR10 tier percent of 0' => [['tiers' => [['min_quantity' => 10, 'discount_percent' => 0]]], 'tiers.0.discount_percent'],
    'BR-MOQ01 moq of 0' => [['moq' => 0], 'moq'],
    'D91 unit not an id' => [['unit_id' => 'carton'], 'unit_id'],
]);

test('BR-T04 bulk pricing never touches another tenant', function (): void {
    $foreign = $this->inTenant(
        fn () => Product::factory()->withSku(['cover_price' => '120.00', 'base_price' => '120.00'])->create(['name' => 'علوم']),
        $this->otherTenant(),
    );

    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['filter' => ['q' => 'علوم']]))
        ->assertOk()
        ->assertJsonPath('data.affected', 1);

    $this->assertDatabaseHas('sku_units', ['id' => bulkPricingUnitId($foreign), 'base_price' => '120.00']);
});
