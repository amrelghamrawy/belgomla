<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ReorderProductImages;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->product = Product::factory()->create();
    $this->a = ProductImage::factory()->for($this->product)->create(['sort_order' => 1]);
    $this->b = ProductImage::factory()->for($this->product)->create(['sort_order' => 2]);
});

test('sets the order from the list and returns the gallery in that order, audited', function (): void {
    $gallery = app(ReorderProductImages::class)->handle($this->product, [$this->b->id, $this->a->id]);

    expect($gallery->pluck('id')->all())->toBe([$this->b->id, $this->a->id])
        ->and($gallery->pluck('sort_order')->all())->toBe([1, 2])
        ->and(AuditLog::query()->sole()->new_values)->toBe(['ids' => [$this->b->id, $this->a->id]]);
});

test('the list must contain every image of the product exactly once', function (array $ids): void {
    app(ReorderProductImages::class)->handle($this->product, $ids);
})->with([
    'missing one' => fn (): array => [[$this->a->id]],
    'repeated' => fn (): array => [[$this->a->id, $this->a->id]],
    'extra unknown id' => fn (): array => [[$this->a->id, $this->b->id, 999_999]],
])->throws(InvalidCatalogInput::class);
