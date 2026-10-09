<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;

beforeEach(function (): void {
    $this->book = $this->inTenant(fn () => Product::factory()->withSku()->create(['name' => 'علوم أولى إعدادي']));
});

test('the product page shows the list shape plus gallery and related products', function (): void {
    [$image, $revision] = $this->inTenant(function (): array {
        $revision = Product::factory()->withSku()->create(['name' => 'المراجعة النهائية علوم']);
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
