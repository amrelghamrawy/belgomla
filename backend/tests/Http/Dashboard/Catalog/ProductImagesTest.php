<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->product = $this->inTenant(fn () => Product::factory()->withSku()->create());
});

test('BR-P07 the first upload becomes the main image, stored under the tenant folder, audited', function (): void {
    $response = $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", ['file' => UploadedFile::fake()->image('cover.jpg')])
        ->assertCreated()
        ->assertExactJsonStructure(['data' => ['id', 'url', 'is_main', 'sort_order']])
        ->assertJsonPath('data.is_main', true)
        ->assertJsonPath('data.sort_order', 1);

    $image = ProductImage::withoutTenantScope()->findOrFail($response->json('data.id'));

    expect($image->path)->toStartWith("tenants/{$this->tenant->id}/products/{$this->product->id}/");
    Storage::disk('public')->assertExists($image->path);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product_image.added', 'entity_id' => $image->id, 'actor_id' => $this->me->id]);
});

test('BR-P07 later uploads join the gallery unless sent as main', function (): void {
    $first = $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", ['file' => UploadedFile::fake()->image('a.jpg')])->json('data.id');

    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", ['file' => UploadedFile::fake()->image('b.jpg')])
        ->assertJsonPath('data.is_main', false)
        ->assertJsonPath('data.sort_order', 2);

    $main = $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", ['file' => UploadedFile::fake()->image('c.jpg'), 'is_main' => true])
        ->assertJsonPath('data.is_main', true)
        ->json('data.id');

    $this->assertDatabaseHas('product_images', ['id' => $first, 'is_main' => false]);
    $this->assertDatabaseHas('product_images', ['id' => $main, 'is_main' => true]);
});

test('D26 a ninth image is rejected', function (): void {
    $this->inTenant(fn () => ProductImage::factory()->count(8)->for($this->product)->create());

    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", ['file' => UploadedFile::fake()->image('x.jpg')])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'gallery_full')
        ->assertJsonPath('error.details.max', 8);

    $this->assertDatabaseCount('product_images', 8);
});

test('image upload validation', function (array $body, string $field): void {
    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing file' => fn (): array => [[], 'file'],
    'not an image' => fn (): array => [['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], 'file'],
    'D26 over 2 MB' => fn (): array => [['file' => UploadedFile::fake()->image('big.jpg')->size(2049)], 'file'],
    'is_main not boolean' => fn (): array => [['file' => UploadedFile::fake()->image('a.jpg'), 'is_main' => 'yes'], 'is_main'],
]);

test('removing the main image deletes the file and promotes the next image, audited', function (): void {
    Storage::disk('public')->put('tenants/x/main.jpg', 'x');
    [$main, $next] = $this->inTenant(fn (): array => [
        ProductImage::factory()->for($this->product)->main()->create(['path' => 'tenants/x/main.jpg', 'sort_order' => 1]),
        ProductImage::factory()->for($this->product)->create(['sort_order' => 2]),
    ]);

    $this->tenantJson('DELETE', "/api/dashboard/products/{$this->product->id}/images/{$main->id}")->assertNoContent();

    Storage::disk('public')->assertMissing('tenants/x/main.jpg');
    $this->assertDatabaseMissing('product_images', ['id' => $main->id]);
    $this->assertDatabaseHas('product_images', ['id' => $next->id, 'is_main' => true]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product_image.removed', 'entity_id' => $main->id]);
});

test('images are reordered by listing every id in the new order, audited', function (): void {
    [$a, $b] = $this->inTenant(fn (): array => [
        ProductImage::factory()->for($this->product)->main()->create(['sort_order' => 1]),
        ProductImage::factory()->for($this->product)->create(['sort_order' => 2]),
    ]);

    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images/reorder", ['ids' => [$b->id, $a->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $b->id)
        ->assertJsonPath('data.0.sort_order', 1)
        ->assertJsonPath('data.1.id', $a->id)
        ->assertJsonPath('data.1.is_main', true);

    $this->assertDatabaseHas('audit_logs', ['action' => 'product_image.reordered', 'entity_id' => $this->product->id]);
});

test('reordering that leaves out an image is rejected', function (): void {
    [$a] = $this->inTenant(fn (): array => [
        ProductImage::factory()->for($this->product)->create(['sort_order' => 1]),
        ProductImage::factory()->for($this->product)->create(['sort_order' => 2]),
    ]);

    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images/reorder", ['ids' => [$a->id]])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['ids']]]);
});

test('reordering with an image of another product is rejected and changes nothing', function (): void {
    $a = $this->inTenant(fn () => ProductImage::factory()->for($this->product)->create(['sort_order' => 1]));
    $other = $this->inTenant(fn () => ProductImage::factory()->create(['sort_order' => 1]));

    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images/reorder", ['ids' => [$other->id, $a->id]])
        ->assertUnprocessable();

    $this->assertDatabaseHas('product_images', ['id' => $other->id, 'sort_order' => 1]);
});

test('reorder validation', function (array $body, string $field): void {
    $this->tenantJson('POST', "/api/dashboard/products/{$this->product->id}/images/reorder", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing ids' => [[], 'ids'],
    'empty ids' => [['ids' => []], 'ids'],
    'non-integer id' => [['ids' => ['a']], 'ids.0'],
    'duplicate ids' => [['ids' => [1, 1]], 'ids.0'],
]);

test('BR-T04 an image cannot be removed through another product', function (): void {
    $image = $this->inTenant(fn () => ProductImage::factory()->create());

    $this->tenantJson('DELETE', "/api/dashboard/products/{$this->product->id}/images/{$image->id}")->assertNotFound();

    $this->assertDatabaseHas('product_images', ['id' => $image->id]);
});

test('BR-T04 an image of another tenant cannot be removed', function (): void {
    [$product, $image] = $this->inTenant(function (): array {
        $product = Product::factory()->create();

        return [$product, ProductImage::factory()->for($product)->create()];
    }, $this->otherTenant());

    $this->tenantJson('DELETE', "/api/dashboard/products/{$product->id}/images/{$image->id}")->assertNotFound();

    $this->assertDatabaseHas('product_images', ['id' => $image->id]);
});

test('BR-T04 uploading an image to a product of another tenant is 404', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->create(), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/products/{$foreign->id}/images", ['file' => UploadedFile::fake()->image('a.jpg')])
        ->assertNotFound();

    $this->assertDatabaseCount('product_images', 0);
});

test('BR-T04 reordering images of a product of another tenant is 404', function (): void {
    [$product, $image] = $this->inTenant(function (): array {
        $product = Product::factory()->create();

        return [$product, ProductImage::factory()->for($product)->create(['sort_order' => 3])];
    }, $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/products/{$product->id}/images/reorder", ['ids' => [$image->id]])->assertNotFound();

    $this->assertDatabaseHas('product_images', ['id' => $image->id, 'sort_order' => 3]);
});
