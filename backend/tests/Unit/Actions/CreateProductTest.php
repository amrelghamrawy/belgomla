<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $basics = $this->catalogBasics();
    $this->carton = fn (array $unit = []): SkuUnitData => new SkuUnitData(...[
        'id' => null, 'unitId' => $basics['carton']->id, 'conversionFactor' => 12, 'isDefault' => true,
        'pricing' => new SkuPricingData(basePrice: '30.00'), ...$unit,
    ]);
    $this->data = fn (array $unit = [], array $customFields = [], array $more = []): ProductData => new ProductData(
        name: 'قلم جاف',
        categoryId: $basics['category']->id,
        sku: new SkuData('PEN-1', $basics['piece']->id, [($this->carton)($unit), ...$more]),
        customFields: $customFields,
    );
});

test('BR-P02 creates an active product with exactly one sku and a system audit record', function (): void {
    $product = app(CreateProduct::class)->handle(($this->data)());
    $unit = $product->sku?->activeUnits->sole();

    expect($product->status)->toBe(ProductStatus::Active)
        ->and($product->skus()->count())->toBe(1)
        ->and($unit?->conversion_factor)->toBe(12)
        ->and($unit?->moq)->toBe(1)
        ->and($unit?->is_default)->toBeTrue()
        ->and($product->created_by)->toBeNull()
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['name' => 'قلم جاف', 'sku.code' => 'PEN-1', 'sku.units' => [[
            'unit_id' => $this->catalogBasics()['carton']->id, 'conversion_factor' => 12, 'is_default' => true, 'cover_price' => null,
            'base_price' => '30.00', 'base_price_percent' => null, 'moq' => 1, 'tiers' => [],
        ]]]);
});

test('D91 a sku is created in every unit sent, in order, the one flagged the default', function (): void {
    $piece = $this->catalogBasics()['piece']->id;

    $product = app(CreateProduct::class)->handle(($this->data)(['isDefault' => false], more: [($this->carton)(['unitId' => $piece, 'conversionFactor' => 1, 'pricing' => new SkuPricingData('3.00', moq: 6)])]));

    expect($product->sku?->activeUnits->map->only(['unit_id', 'conversion_factor', 'base_price', 'moq', 'is_default', 'sort_order'])->all())->toBe([
        ['unit_id' => $piece, 'conversion_factor' => 1, 'base_price' => '3.00', 'moq' => 6, 'is_default' => true, 'sort_order' => 1],
        ['unit_id' => $this->catalogBasics()['carton']->id, 'conversion_factor' => 12, 'base_price' => '30.00', 'moq' => 1, 'is_default' => false, 'sort_order' => 0],
    ]);
});

test('BR-INV06 the opening stock is recorded by the creating user, inside the creation audit', function (): void {
    $user = $this->makeOwner();

    $product = app(CreateProduct::class)->handle(new ProductData(...[...get_object_vars(($this->data)()), 'openingStock' => 36]), $user->id);

    expect(SkuInventory::query()->sole()->only(['sku_id', 'on_hand', 'reserved']))->toBe(['sku_id' => $product->sku->id, 'on_hand' => 36, 'reserved' => 0])
        ->and(InventoryMovement::query()->sole()->only(['type', 'quantity', 'on_hand_after', 'actor_id']))->toBe(['type' => MovementType::Opening, 'quantity' => 36, 'on_hand_after' => 36, 'actor_id' => $user->id])
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['opening_stock' => 36]);
});

test('BR-PR10 the sku prices and tiers are written through pricing, inside the creation audit only', function (): void {
    $product = app(CreateProduct::class)->handle(($this->data)(['pricing' => new SkuPricingData(
        basePrice: null,
        basePricePercent: '25.00',
        tiers: [new TierData(10, discountPercent: '30.00'), new TierData(50, '80.00')],
        coverPrice: '120.00',
    )]));

    expect($product->sku?->activeUnits->sole()->base_price)->toBe('90.00')
        ->and($product->sku?->activeUnits->sole()->tiers->map->only(['min_quantity', 'unit_price', 'discount_percent'])->all())->toBe([
            ['min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00'],
            ['min_quantity' => 50, 'unit_price' => '80.00', 'discount_percent' => null],
        ])
        ->and(AuditLog::query()->sole()->new_values['sku.units'][0]['tiers'] ?? null)->toEqual([
            ['min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00'],
            ['min_quantity' => 50, 'unit_price' => '80.00', 'discount_percent' => null],
        ]);
});

test('BR-PR05 D91 invalid tiers stop the creation before anything is written, named after their unit', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(($this->data)(['pricing' => new SkuPricingData('30.00', tiers: [new TierData(5, '20.00'), new TierData(5, '19.00')])])))
        ->toThrow(function (ApiException $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'invalid_tiers', ['sku.units.0.tiers' => ['الحد الأدنى 5 مكرر في أكثر من شريحة']]]);
        });

    expect(Product::query()->count())->toBe(0)
        ->and(SkuPriceTier::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('stores the creating user', function (): void {
    $owner = $this->makeOwner();

    expect(app(CreateProduct::class)->handle(($this->data)(), $owner->id)->created_by)->toBe($owner->id);
});

test('BR-P08 stores the normalised custom fields', function (): void {
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);

    expect(app(CreateProduct::class)->handle(($this->data)(customFields: ['pages' => '96']))->custom_fields)->toBe(['pages' => 96]);
});

test('invalid custom fields stop the creation before anything is written', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(($this->data)(customFields: ['nope' => 1])))->toThrow(InvalidCustomFields::class);

    expect(Product::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('a failing sku unit insert rolls back the product and the audit record', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(($this->data)(['conversionFactor' => 0])))->toThrow(QueryException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(Sku::query()->count())->toBe(0)
        ->and(SkuUnit::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('an invalid opening stock rolls back the product, its sku and the audit record', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(new ProductData(...[...get_object_vars(($this->data)()), 'openingStock' => -1])))->toThrow(RuntimeException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(Sku::query()->count())->toBe(0)
        ->and(SkuInventory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('V2 Task 06 the creation is audited under the batch it is given', function (): void {
    app(CreateProduct::class)->handle(($this->data)(), batchId: '0d1c2b3a-4f5e-4d6c-8b7a-9f8e7d6c5b4a');

    expect(AuditLog::query()->where('action', 'product.created')->value('batch_id'))->toBe('0d1c2b3a-4f5e-4d6c-8b7a-9f8e7d6c5b4a');
});

test('D88 a product starts without pre-orders unless the settings are given, which the creation audit records', function (): void {
    $basics = $this->catalogBasics();
    $plain = app(CreateProduct::class)->handle(($this->data)());
    $preorderable = app(CreateProduct::class)->handle(new ProductData(
        name: 'سلاح التلميذ ٢٠٢٧',
        categoryId: $basics['category']->id,
        sku: new SkuData('BOOK-27', $basics['piece']->id, [($this->carton)()]),
        status: ProductStatus::ComingSoon,
        preorder: new App\Modules\Catalog\Data\PreorderData(true, Carbon\CarbonImmutable::parse('2026-12-01', 'UTC'), 500),
    ));

    expect($plain->refresh()->only(['preorder_enabled', 'expected_at', 'preorder_limit']))->toBe(['preorder_enabled' => false, 'expected_at' => null, 'preorder_limit' => null])
        ->and($preorderable->refresh()->preorder_enabled)->toBeTrue()
        ->and($preorderable->expected_at?->toDateString())->toBe('2026-12-01')
        ->and($preorderable->preorder_limit)->toBe(500)
        ->and(AuditLog::query()->where('entity_id', $preorderable->id)->sole()->new_values)->toMatchArray(['preorder_enabled' => true, 'expected_at' => '2026-12-01', 'preorder_limit' => 500]);
});
