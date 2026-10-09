<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Catalog\Services\SlugGenerator;

/**
 * Invariants only: the slug on create, and facet-cache invalidation whenever a product changes (Tech §9b).
 */
final readonly class ProductObserver
{
    public const int SLUG_LENGTH = 220;

    public function __construct(
        private SlugGenerator $slugs,
        private FacetCacheVersion $facets,
    ) {}

    public function creating(Product $product): void
    {
        if (($product->getAttributes()['slug'] ?? '') === '') {
            $product->slug = $this->slugs->unique(Product::class, $product->name, self::SLUG_LENGTH);
        }
    }

    public function saved(Product $product): void
    {
        $this->facets->bump($product->tenant_id);
    }

    public function deleted(Product $product): void
    {
        $this->facets->bump($product->tenant_id);
    }
}
