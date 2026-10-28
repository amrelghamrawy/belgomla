<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;

/*
| BR-P14 (V2 Task 14, D92): a product with options («اللون», «المقاس») sent as `options` + `skus`.
*/

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $basics = $this->catalogBasics();
    $this->variant = fn (string $code, array $values, array $extra = []): array => [
        'code' => $code, 'base_unit_id' => $basics['piece']->id, 'option_values' => $values,
        'units' => [['unit_id' => $basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '10.00', 'moq' => 1]],
        ...$extra,
    ];
    $this->body = (fn (array $overrides = []): array => [
        'name' => 'شنطة مدرسية',
        'category_id' => $basics['category']->id,
        'options' => [
            ['name' => 'اللون', 'values' => [['value' => 'أزرق'], ['value' => 'أسود'], ['value' => 'أحمر']]],
            ['name' => 'المقاس', 'values' => [['value' => 'S'], ['value' => 'M']]],
        ],
        'skus' => [
            ($this->variant)('BAG-1-S', ['أزرق', 'S'], ['opening_stock' => 10]),
            ($this->variant)('BAG-1-M', ['أزرق', 'M'], ['opening_stock' => 11]),
            ($this->variant)('BAG-2-S', ['أسود', 'S'], ['opening_stock' => 12]),
            ($this->variant)('BAG-2-M', ['أسود', 'M'], ['opening_stock' => 13]),
            ($this->variant)('BAG-3-S', ['أحمر', 'S'], ['opening_stock' => 14]),
            ($this->variant)('BAG-3-M', ['أحمر', 'M'], ['opening_stock' => 15, 'units' => [['unit_id' => $basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '12.50']]]),
        ],
        ...$overrides,
    ]);
});

test('BR-P14 a 3 colour by 2 size product is created with one sku per combination, its stock and the options', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/products', ($this->body)())
        ->assertCreated()
        ->assertJsonCount(6, 'data.skus')
        ->assertJsonPath('data.options.0.name', 'اللون')
        ->assertJsonPath('data.options.0.values.*.value', ['أزرق', 'أسود', 'أحمر'])
        ->assertJsonPath('data.options.1.values.*.value', ['S', 'M'])
        ->assertJsonPath('data.skus.*.label', ['أزرق / S', 'أزرق / M', 'أسود / S', 'أسود / M', 'أحمر / S', 'أحمر / M'])
        ->assertJsonPath('data.skus.5.option_values', ['أحمر', 'M'])
        ->assertJsonPath('data.skus.5.base_price', '12.50')
        ->assertJsonPath('data.skus.*.inventory.on_hand', [10, 11, 12, 13, 14, 15])
        ->assertJsonPath('data.skus.*.status', ['active', 'active', 'active', 'active', 'active', 'active'])
        ->assertJsonPath('data.sku.code', 'BAG-1-S');

    $audit = AuditLog::query()->where('action', 'product.created')->sole();

    expect($this->inTenant(fn () => Sku::query()->count()))->toBe(6)
        ->and($audit->new_values['opening_stock'])->toBe(75)
        ->and($audit->new_values['options'])->toBe([['name' => 'اللون', 'values' => ['أزرق', 'أسود', 'أحمر']], ['name' => 'المقاس', 'values' => ['S', 'M']]])
        ->and(array_column($audit->new_values['skus'], 'label'))->toBe(['أزرق / S', 'أزرق / M', 'أسود / S', 'أسود / M', 'أحمر / S', 'أحمر / M'])
        ->and($response->json('data.id'))->toBeInt();
});

test('BR-P14 a product may sell only some combinations', function (): void {
    $body = ($this->body)();
    $body['skus'] = array_slice($body['skus'], 0, 3);

    $this->tenantJson('POST', '/api/dashboard/products', $body)
        ->assertCreated()
        ->assertJsonCount(3, 'data.skus');
});

test('BR-P14 D92 the variant payload is validated', function (array $overrides, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/products', array_replace(($this->body)(), $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseCount('products', 0);
})->with([
    'D92 a third option' => [['options' => [['name' => 'أ', 'values' => [['value' => '1']]], ['name' => 'ب', 'values' => [['value' => '1']]], ['name' => 'ج', 'values' => [['value' => '1']]]]], 'options'],
    'D92 more than 20 values' => [['options' => [['name' => 'اللون', 'values' => array_map(fn (int $i): array => ['value' => "V{$i}"], range(1, 21))]]], 'options.0.values'],
    'an option without values' => [['options' => [['name' => 'اللون', 'values' => []]]], 'options.0.values'],
    'an option name too long' => [['options' => [['name' => str_repeat('ل', 51), 'values' => [['value' => 'أ']]]]], 'options.0.name'],
    'a duplicate option name, folded' => [['options' => [['name' => 'اللون', 'values' => [['value' => 'أ']]], ['name' => 'اللوْن', 'values' => [['value' => 'ب']]]]], 'options.1.name'],
    'a duplicate value, folded' => [['options' => [['name' => 'اللون', 'values' => [['value' => 'أزرق'], ['value' => 'ازرق']]]]], 'options.0.values.1.value'],
    'both sku and skus' => [['sku' => ['code' => 'X']], 'sku'],
]);

test('BR-P14 every sku names one known value of each option, once per combination, at least one active', function (array $skus, string $field): void {
    $body = ($this->body)(['skus' => array_map(fn (array $sku): array => ($this->variant)(...$sku), $skus)]);

    $this->tenantJson('POST', '/api/dashboard/products', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'a value missing' => [[['A-1', ['أزرق']]], 'skus.0.option_values'],
    'an unknown value' => [[['A-1', ['أخضر', 'S']]], 'skus.0.option_values'],
    'values in the wrong option order' => [[['A-1', ['S', 'أزرق']]], 'skus.0.option_values'],
    'a combination twice' => [[['A-1', ['أزرق', 'S']], ['A-2', ['ازرق', 'S']]], 'skus.1.option_values'],
    'a code twice' => [[['A-1', ['أزرق', 'S']], ['a-1', ['أزرق', 'M']]], 'skus.1.code'],
    'every sku inactive' => [[['A-1', ['أزرق', 'S'], ['status' => 'inactive']]], 'skus'],
    'an unknown status' => [[['A-1', ['أزرق', 'S'], ['status' => 'archived']]], 'skus.0.status'],
]);

test('BR-P14 without options a product has a single sku', function (): void {
    $body = ($this->body)(['options' => [], 'skus' => [($this->variant)('A-1', []), ($this->variant)('A-2', [])]]);

    $this->tenantJson('POST', '/api/dashboard/products', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['skus']]]);
});

test('D91 a unit is sold at most once per variant, but two variants may share it', function (): void {
    $body = ($this->body)();
    $body['skus'][0]['units'][] = $body['skus'][0]['units'][0];

    $this->tenantJson('POST', '/api/dashboard/products', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['skus.0.units.1.unit_id']]]);
});

test('BR-PR01 a price refused for one variant names that variant', function (): void {
    $body = ($this->body)();
    $body['skus'][2]['units'][0]['base_price'] = '0';

    $this->tenantJson('POST', '/api/dashboard/products', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['skus.2.units.0.base_price']]]);
});

test('BR-P03 a variant code taken by another product is a 409 naming the variant', function (): void {
    $this->inTenant(fn () => Product::factory()->withSku(['code' => 'BAG-2-S'])->create());

    $this->tenantJson('POST', '/api/dashboard/products', ($this->body)())
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_code')
        ->assertJsonStructure(['error' => ['details' => ['skus.2.code']]]);

    $this->assertDatabaseCount('product_options', 0);
});

test('BR-P14 BR-P05 an update removes a size: unsold variants go, sold ones stay inactive, audited', function (): void {
    $product = Product::query()->findOrFail($this->tenantJson('POST', '/api/dashboard/products', ($this->body)())->json('data.id'));
    $this->inTenant(function (): void {
        $sold = Sku::query()->where('code', 'BAG-1-M')->sole();
        App\Modules\Orders\Models\Order::factory()->withItems([['sku_id' => $sold->id, 'quantity' => 1]])->create();
        Sku::query()->whereNot('code', 'BAG-1-M')->get()->each(function (Sku $sku): void {
            App\Modules\Inventory\Models\InventoryMovement::query()->where('sku_id', $sku->id)->delete();
            SkuInventory::query()->whereKey($sku->id)->update(['on_hand' => 0]);
        });
    });
    $shown = $this->tenantJson('GET', "/api/dashboard/products/{$product->id}")->json('data');
    $body = ($this->body)([
        'options' => [['id' => $shown['options'][0]['id'], 'name' => 'اللون', 'values' => array_map(fn (array $value): array => ['id' => $value['id'], 'value' => $value['value']], $shown['options'][0]['values'])], ['id' => $shown['options'][1]['id'], 'name' => 'المقاس', 'values' => [['value' => 'S']]]],
        'skus' => array_values(array_map(fn (array $sku): array => ['id' => $sku['id'], ...($this->variant)($sku['code'], $sku['option_values'])], array_filter($shown['skus'], fn (array $sku): bool => $sku['option_values'][1] === 'S'))),
    ]);

    $this->tenantJson('PUT', "/api/dashboard/products/{$product->id}", $body)
        ->assertOk()
        ->assertJsonPath('data.skus.*.code', ['BAG-1-S', 'BAG-2-S', 'BAG-3-S', 'BAG-1-M'])
        ->assertJsonPath('data.skus.*.status', ['active', 'active', 'active', 'inactive'])
        ->assertJsonPath('data.skus.3.label', 'أزرق / M')
        ->assertJsonPath('data.options.1.values.*.value', ['S']);

    expect($this->inTenant(fn () => Sku::query()->whereIn('code', ['BAG-2-M', 'BAG-3-M'])->exists()))->toBeFalse()
        ->and(array_column(AuditLog::query()->where('action', 'product.updated')->sole()->new_values['skus'], 'status'))->toBe(['active', 'active', 'active', 'inactive']);
});

test('BR-T04 an option id of another tenant product is not found on its field', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->variantProduct(['أزرق'], tenant: $other);
    $foreignOption = $this->inTenant(fn () => ProductOption::query()->where('product_id', $foreign->id)->sole(), $other);
    $product = Product::query()->findOrFail($this->tenantJson('POST', '/api/dashboard/products', ($this->body)())->json('data.id'));
    $body = ($this->body)();
    $body['options'][0]['id'] = $foreignOption->id;

    $this->tenantJson('PUT', "/api/dashboard/products/{$product->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['options.0.id']]]);

    expect($this->inTenant(fn () => $foreignOption->fresh()?->name, $other))->toBe('اللون');
});

test('BR-P14 a value shows one of the product images as its swatch', function (): void {
    $product = Product::query()->findOrFail($this->tenantJson('POST', '/api/dashboard/products', ($this->body)())->json('data.id'));
    $image = $this->inTenant(fn () => App\Modules\Catalog\Models\ProductImage::factory()->create(['product_id' => $product->id]));
    $body = ($this->body)();
    $body['options'][0]['values'][1]['image_id'] = $image->id;

    $this->tenantJson('PUT', "/api/dashboard/products/{$product->id}", $body)
        ->assertOk()
        ->assertJsonPath('data.options.0.values.1.image_id', $image->id)
        ->assertJsonPath('data.options.0.values.1.image_url', $image->url())
        ->assertJsonPath('data.options.0.values.0.image_url', null);
});

test('BR-P14 the product list shows the first active variant and every variant', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', ($this->body)());

    $this->tenantJson('GET', '/api/dashboard/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.code', 'BAG-1-S')
        ->assertJsonCount(6, 'data.0.skus')
        ->assertJsonPath('data.0.options.1.name', 'المقاس');
});

test('BR-P11 duplicating a product with variants copies them all', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', ($this->body)())->json('data.id');

    $copy = $this->tenantJson('POST', "/api/dashboard/products/{$id}/duplicate")->assertCreated();

    expect($this->inTenant(fn () => Product::query()->findOrFail($copy->json('data.id'))->skus()->pluck('code')->all()))
        ->toBe(['BAG-1-S-COPY', 'BAG-1-M-COPY', 'BAG-2-S-COPY', 'BAG-2-M-COPY', 'BAG-3-S-COPY', 'BAG-3-M-COPY']);
});
