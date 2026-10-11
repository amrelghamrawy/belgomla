<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Models\SkuPriceTier;
use Illuminate\Support\Arr;

const SKU_PRICING_KEYS = [
    'product_id', 'sku_id', 'code', 'cover_price', 'base_price', 'base_price_percent', 'discount_percent', 'moq',
    'tiers' => ['*' => ['min_quantity', 'unit_price', 'discount_percent']],
];

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->product = $this->inTenant(function (): Product {
        $product = Product::factory()->withSku(['code' => 'ADW-1', 'cover_price' => '120.00', 'base_price' => '100.00', 'moq' => 1])->create();
        SkuPriceTier::factory()->for($product->sku)->create(['min_quantity' => 5, 'unit_price' => '99.00']);

        return $product;
    });
    $this->body = fn (array $overrides = []): array => [
        'cover_price' => '120.00',
        'base_price_percent' => 25,
        'moq' => 5,
        'tiers' => [['min_quantity' => 10, 'discount_percent' => 30], ['min_quantity' => 50, 'unit_price' => '80']],
        ...$overrides,
    ];
});

test('BR-PR10 an owner sets the prices as percents and absolute amounts; tiers are replaced', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)())
        ->assertOk()
        ->assertExactJsonStructure(['data' => SKU_PRICING_KEYS])
        ->assertJsonPath('data.product_id', $this->product->id)
        ->assertJsonPath('data.code', 'ADW-1')
        ->assertJsonPath('data.cover_price', '120.00')
        ->assertJsonPath('data.base_price', '90.00')
        ->assertJsonPath('data.base_price_percent', '25.00')
        ->assertJsonPath('data.discount_percent', '25.00')
        ->assertJsonPath('data.moq', 5)
        ->assertJsonPath('data.tiers', [
            ['min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00'],
            ['min_quantity' => 50, 'unit_price' => '80.00', 'discount_percent' => null],
        ]);

    $this->assertDatabaseMissing('sku_price_tiers', ['sku_id' => $this->product->sku->id, 'min_quantity' => 5]);
});

test('BR-L the change is audited as price, tiers and moq records by the acting owner', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)())->assertOk();

    $logs = $this->inTenant(fn () => AuditLog::query()->get()->keyBy('action'));

    expect($logs->keys()->sort()->values()->all())->toBe(['sku.moq_changed', 'sku.price_changed', 'sku.tiers_changed'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id])
        ->and($logs['sku.price_changed']->old_values)->toEqual(['base_price' => '100.00', 'base_price_percent' => null])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '90.00', 'base_price_percent' => '25.00'])
        ->and($logs['sku.moq_changed']->new_values)->toEqual(['moq' => 5]);
});

test('a cover price that is not sent is kept, and an empty tier list removes every tier', function (): void {
    $body = ($this->body)(['tiers' => [], 'base_price_percent' => 50]);
    unset($body['cover_price']);

    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", $body)
        ->assertOk()
        ->assertJsonPath('data.cover_price', '120.00')
        ->assertJsonPath('data.base_price', '60.00')
        ->assertJsonPath('data.tiers', []);
});

test('BR-PR05 duplicate thresholds are refused with invalid_tiers and nothing changes', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)([
        'tiers' => [['min_quantity' => 10, 'unit_price' => '85'], ['min_quantity' => 10, 'unit_price' => '80']],
    ]))
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'invalid_tiers',
            'message' => 'شرائح الأسعار غير صحيحة',
            'details' => ['tiers' => ['الحد الأدنى 10 مكرر في أكثر من شريحة']],
        ]]);

    $this->assertDatabaseHas('skus', ['id' => $this->product->sku->id, 'base_price' => '100.00', 'moq' => 1]);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_id' => $this->product->sku->id, 'min_quantity' => 5]);
});

test('D28 a tier priced above the base price is refused with invalid_tiers', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)(['tiers' => [['min_quantity' => 10, 'unit_price' => '95']]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_tiers')
        ->assertJsonPath('error.details.tiers.0', 'سعر شريحة 10 أعلى من السعر الأساسي');
});

test('BR-PR10 a percent on a sku without a cover price is refused with cover_price_required', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)(['cover_price' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cover_price_required')
        ->assertJsonPath('error.message', 'أدخل سعر القطاعي أولًا لحساب السعر بنسبة الخصم');

    $this->assertDatabaseHas('skus', ['id' => $this->product->sku->id, 'cover_price' => '120.00']);
});

test('pricing validation', function (array $overrides, string $field, array $without = []): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", Arr::except(($this->body)($overrides), $without))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'BR-PR01 neither base price nor percent' => [['base_price_percent' => null], 'base_price'],
    'BR-PR01 zero base price' => [['base_price_percent' => null, 'base_price' => 0], 'base_price'],
    'base price with 3 decimals' => [['base_price_percent' => null, 'base_price' => '1.005'], 'base_price'],
    'BR-PR10 percent of 100' => [['base_price_percent' => 100], 'base_price_percent'],
    'BR-PR10 percent of 0' => [['base_price_percent' => 0], 'base_price_percent'],
    'negative cover price' => [['cover_price' => -1], 'cover_price'],
    'BR-MOQ01 missing moq' => [[], 'moq', ['moq']],
    'BR-MOQ01 moq of 0' => [['moq' => 0], 'moq'],
    'BR-U05 fractional moq' => [['moq' => 2.5], 'moq'],
    'missing tiers' => [[], 'tiers', ['tiers']],
    'tiers not a list' => [['tiers' => 'x'], 'tiers'],
    'more than 20 tiers' => [['tiers' => array_map(fn (int $i): array => ['min_quantity' => $i, 'unit_price' => 1], range(1, 21))], 'tiers'],
    'BR-PR03 tier without a minimum' => [['tiers' => [['unit_price' => 80]]], 'tiers.0.min_quantity'],
    'BR-PR03 tier minimum of 0' => [['tiers' => [['min_quantity' => 0, 'unit_price' => 80]]], 'tiers.0.min_quantity'],
    'BR-U05 fractional tier minimum' => [['tiers' => [['min_quantity' => 1.5, 'unit_price' => 80]]], 'tiers.0.min_quantity'],
    'BR-PR03 tier without a price or percent' => [['tiers' => [['min_quantity' => 10]]], 'tiers.0.unit_price'],
    'BR-PR05 negative tier price' => [['tiers' => [['min_quantity' => 10, 'unit_price' => -1]]], 'tiers.0.unit_price'],
    'BR-PR10 tier percent of 100' => [['tiers' => [['min_quantity' => 10, 'discount_percent' => 100]]], 'tiers.0.discount_percent'],
]);

test('BR-T04 the pricing of another tenant product is 404 and stays untouched', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku(['base_price' => '100.00'])->create(), $this->otherTenant());

    $this->tenantJson('PUT', "/api/dashboard/products/{$foreign->id}/pricing", ($this->body)())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('skus', ['product_id' => $foreign->id, 'base_price' => '100.00']);
});

test('a product without a sku has no pricing: 404', function (): void {
    $bare = $this->inTenant(fn () => Product::factory()->create());

    $this->tenantJson('PUT', "/api/dashboard/products/{$bare->id}/pricing", ($this->body)())->assertNotFound();
});

test('BR-PR04 the tiers set here are what the storefront shows', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/products/{$this->product->id}/pricing", ($this->body)())->assertOk();

    $this->tenantJson('GET', "/api/store/products/{$this->product->slug}")
        ->assertOk()
        ->assertJsonPath('data.sku.base_price', '90.00')
        ->assertJsonPath('data.sku.discount_percent', '25.00')
        ->assertJsonPath('data.sku.tiers', [['min_quantity' => 10, 'unit_price' => '84.00'], ['min_quantity' => 50, 'unit_price' => '80.00']]);
});
