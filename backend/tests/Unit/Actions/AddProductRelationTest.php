<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\AddProductRelation;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->book = Product::factory()->create();
    $this->revision = Product::factory()->create();
});

test('BR-P10 creates a typed relation with the related product loaded, audited', function (): void {
    $relation = app(AddProductRelation::class)->handle($this->revision, $this->book->id, RelationType::RevisionOf);

    expect($relation->type)->toBe(RelationType::RevisionOf)
        ->and($relation->relatedProduct->is($this->book))->toBeTrue()
        ->and(AuditLog::query()->sole()->new_values)->toEqual(['product_id' => $this->revision->id, 'related_product_id' => $this->book->id, 'type' => 'revision_of']);
});

test('BR-P10 guards', function (Closure $relatedId, string $exception): void {
    ProductRelation::factory()->create(['product_id' => $this->revision->id, 'related_product_id' => $this->book->id, 'type' => RelationType::Companion]);

    expect(fn () => app(AddProductRelation::class)->handle($this->revision, $relatedId->call($this), RelationType::Companion))->toThrow($exception);

    expect(ProductRelation::query()->count())->toBe(1);
})->with([
    'itself' => [fn (): int => $this->revision->id, InvalidCatalogInput::class],
    'unknown product' => [fn (): int => 999_999, InvalidCatalogInput::class],
    'duplicate' => [fn (): int => $this->book->id, CatalogConflict::class],
]);

test('BR-T04 a product of another tenant is unknown', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->create(), $this->otherTenant());

    app(AddProductRelation::class)->handle($this->book, $foreign->id, RelationType::Companion);
})->throws(InvalidCatalogInput::class);
