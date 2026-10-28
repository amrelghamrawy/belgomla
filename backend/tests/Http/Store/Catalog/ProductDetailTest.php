<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;

beforeEach(function (): void {
    $this->book = $this->inTenant(fn () => Product::factory()->withSku()->create(['name' => 'علوم أولى إعدادي']));
});

test('the product page shows the list shape plus gallery and related products', function (): void {
    [$image, $revision] = $this->inTenant(function (): array {
        $revision = Product::factory()->withSku(onHand: 10)->create(['name' => 'المراجعة النهائية علوم']);
        ProductRelation::factory()->revisionOf()->create(['product_id' => $revision->id, 'related_product_id' => $this->book->id]);

        return [ProductImage::factory()->for($this->book)->main()->create(), $revision];
    });

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->book->slug))
        ->assertOk()
        ->assertJsonPath('data.id', $this->book->id)
        ->assertJsonPath('data.gallery.0.id', $image->id)
        ->assertJsonPath('data.related.0.type', 'revision_of')
        ->assertJsonPath('data.related.0.direction', 'incoming')
        ->assertJsonPath('data.related.0.product.id', $revision->id)
        ->assertJsonPath('data.related.0.product.sku.available', 10)
        ->assertJsonPath('data.related.0.product.sku.in_stock', true)
        ->assertJsonMissingPath('data.related.0.product.related');
});

test('BR-P10 the revision page shows its main book', function (): void {
    $revision = $this->inTenant(function (): Product {
        $revision = Product::factory()->withSku()->create();
        ProductRelation::factory()->revisionOf()->create(['product_id' => $revision->id, 'related_product_id' => $this->book->id]);

        return $revision;
    });

    $this->tenantJson('GET', '/api/store/products/'.urlencode($revision->slug))
        ->assertJsonPath('data.related.0.direction', 'outgoing')
        ->assertJsonPath('data.related.0.product.id', $this->book->id);
});

test('BR-P10 relations to inactive products are hidden, not deleted', function (): void {
    $relation = $this->inTenant(function (): ProductRelation {
        $old = Product::factory()->withSku()->inactive()->create();

        return ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => $old->id]);
    });

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->book->slug))
        ->assertOk()
        ->assertJsonPath('data.related', []);

    $this->assertDatabaseHas('product_relations', ['id' => $relation->id]);
});

test('BR-P04 an inactive product page is 404', function (): void {
    $this->book->update(['status' => 'inactive']);

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->book->slug))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('BR-T04 a slug of another tenant or an unknown slug is 404', function (string $slug): void {
    $this->inTenant(fn () => Product::factory()->withSku()->create(['name' => 'غريب']), $this->otherTenant());

    $this->tenantJson('GET', '/api/store/products/'.urlencode($slug))->assertNotFound();
})->with(['another tenant' => ['غريب'], 'unknown' => ['no-such-book']]);

test('D1 prices are hidden on the product page and on related products', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);
    $this->inTenant(fn () => ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => Product::factory()->withSku()->create()->id]));

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->book->slug))
        ->assertJsonPath('data.prices_hidden', true)
        ->assertJsonMissingPath('data.sku.base_price')
        ->assertJsonPath('data.related.0.product.prices_hidden', true)
        ->assertJsonMissingPath('data.related.0.product.sku.base_price');
});

test('BR-P14 the page of a variant product lists its variants with labels and option values, and its options in order', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أحمر'], sizes: ['S', 'M']);
    [$colour, $size] = $this->inTenant(fn (): array => $pen->options()->with('values')->get()->all());
    [$colours, $sizes] = [$colour->values->modelKeys(), $size->values->modelKeys()];

    $this->tenantJson('GET', '/api/store/products/'.urlencode($pen->slug))
        ->assertOk()
        ->assertJsonCount(4, 'data.skus')
        ->assertJsonPath('data.skus.0.code', 'PEN-1')
        ->assertJsonPath('data.skus.0.label', 'أزرق / S')
        ->assertJsonPath('data.skus.0.option_value_ids', [$colours[0], $sizes[0]])
        ->assertJsonPath('data.skus.3.label', 'أحمر / M')
        ->assertJsonPath('data.skus.3.option_value_ids', [$colours[1], $sizes[1]])
        ->assertJsonPath('data.options', [
            ['id' => $colour->id, 'name' => 'اللون', 'values' => [
                ['id' => $colours[0], 'value' => 'أزرق', 'image_url' => null],
                ['id' => $colours[1], 'value' => 'أحمر', 'image_url' => null],
            ]],
            ['id' => $size->id, 'name' => 'المقاس', 'values' => [
                ['id' => $sizes[0], 'value' => 'S', 'image_url' => null],
                ['id' => $sizes[1], 'value' => 'M', 'image_url' => null],
            ]],
        ]);
});

test('BR-P14 an inactive variant is hidden and a value only it used leaves the options', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر']);
    $this->inTenant(fn () => Sku::query()->where('code', 'PEN-3')->update(['status' => 'inactive']));

    $this->tenantJson('GET', '/api/store/products/'.urlencode($pen->slug))
        ->assertOk()
        ->assertJsonPath('data.skus.*.code', ['PEN-1', 'PEN-2'])
        ->assertJsonPath('data.options.0.values.*.value', ['أزرق', 'أسود']);
});

test('BR-P14 a product without options shows no options and its one sku', function (): void {
    $sku = $this->inTenant(fn () => $this->book->skus()->sole());

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->book->slug))
        ->assertOk()
        ->assertJsonPath('data.options', [])
        ->assertJsonCount(1, 'data.skus')
        ->assertJsonPath('data.skus.0.id', $sku->id)
        ->assertJsonPath('data.skus.0.label', null)
        ->assertJsonPath('data.skus.0.option_value_ids', [])
        ->assertJsonPath('data.sku.id', $sku->id);
});
