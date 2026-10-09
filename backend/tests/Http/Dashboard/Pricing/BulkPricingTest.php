<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Pricing\Models\SkuPriceTier;

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
            'skipped_no_cover_price' => [['product_id' => $this->noCover->id, 'sku_id' => $this->noCover->sku->id, 'code' => 'ADW-NC', 'name' => 'دراسات']],
        ]]);

    $this->assertDatabaseHas('skus', ['id' => $this->science->sku->id, 'base_price' => '110.00']);
    expect($this->inTenant(fn () => AuditLog::query()->count()))->toBe(0)
        ->and($this->inTenant(fn () => SkuPriceTier::query()->count()))->toBe(0);
});

test('BR-PR11 apply prices the publisher off each cover price in one audit batch', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['moq' => 5]))
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['affected', 'skipped_no_cover_price' => ['*' => ['product_id', 'sku_id', 'code', 'name']], 'batch_id']])
        ->assertJsonPath('data.affected', 2)
        ->assertJsonPath('data.skipped_no_cover_price.0.sku_id', $this->noCover->sku->id);

    $logs = $this->inTenant(fn () => AuditLog::query()->get());

    $this->assertDatabaseHas('skus', ['id' => $this->science->sku->id, 'base_price' => '90.00', 'base_price_percent' => '25.00', 'moq' => 5]);
    $this->assertDatabaseHas('skus', ['id' => $this->math->sku->id, 'base_price' => '60.00']);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_id' => $this->science->sku->id, 'min_quantity' => 50, 'unit_price' => '78.00', 'discount_percent' => '35.00']);
    $this->assertDatabaseHas('skus', ['id' => $this->noCover->sku->id, 'base_price' => '50.00']);
    $this->assertDatabaseHas('skus', ['id' => $this->otherBook->sku->id, 'base_price' => '100.00']);
    expect($logs->pluck('batch_id')->unique()->all())->toBe([$response->json('data.batch_id')])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id])
        ->and($logs->where('action', 'pricing.bulk_applied'))->toHaveCount(1)
        ->and($logs->where('action', 'sku.price_changed'))->toHaveCount(2);
});

test('BR-PR05 duplicate thresholds fail preview and apply alike with invalid_tiers', function (string $uri): void {
    $this->tenantJson('POST', $uri, ($this->body)(['tiers' => [['min_quantity' => 10, 'discount_percent' => 30], ['min_quantity' => 10, 'discount_percent' => 35]]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_tiers');

    $this->assertDatabaseHas('skus', ['id' => $this->science->sku->id, 'base_price' => '110.00']);
})->with(['preview' => ['/api/dashboard/pricing/bulk/preview'], 'apply' => ['/api/dashboard/pricing/bulk']]);

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
]);

test('BR-T04 bulk pricing never touches another tenant', function (): void {
    $foreign = $this->inTenant(
        fn () => Product::factory()->withSku(['cover_price' => '120.00', 'base_price' => '120.00'])->create(['name' => 'علوم']),
        $this->otherTenant(),
    );

    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ($this->body)(['filter' => ['q' => 'علوم']]))
        ->assertOk()
        ->assertJsonPath('data.affected', 1);

    $this->assertDatabaseHas('skus', ['product_id' => $foreign->id, 'base_price' => '120.00']);
});
