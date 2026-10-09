<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\GalleryFull;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-P07 / D26: adds a gallery image (max 8). The first image, or one sent with is_main, becomes the
 * main image; the previous main image stays in the gallery. Appended at the end of the order.
 */
final readonly class AddProductImage
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, UploadedFile $file, bool $isMain = false): ProductImage
    {
        $images = ProductImage::query()->where('product_id', $product->id);

        if ($images->count() >= GalleryFull::MAX) {
            throw GalleryFull::make();
        }

        $path = $file->store("tenants/{$this->context->id()}/products/{$product->id}", 'public');

        if ($path === false) {
            throw new RuntimeException('Could not store the product image.');
        }

        return DB::transaction(function () use ($product, $path, $isMain): ProductImage {
            $images = ProductImage::query()->where('product_id', $product->id);
            $makeMain = $isMain || (clone $images)->where('is_main', true)->doesntExist();
            $next = (clone $images)->max('sort_order');

            if ($makeMain) {
                (clone $images)->where('is_main', true)->update(['is_main' => false]);
            }

            $image = ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => $path,
                'is_main' => $makeMain,
                'sort_order' => (is_numeric($next) ? (int) $next : 0) + 1,
            ]);

            $this->audit->log('product_image.added', $image, null, ['product_id' => $product->id, 'path' => $path, 'is_main' => $makeMain]);

            return $image->refresh();
        });
    }
}
