<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Sets the gallery order; the ids must be exactly the product's images. Owns its transaction. */
final readonly class ReorderProductImages
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<int>  $ids  image ids in the new order
     * @return Collection<int, ProductImage>
     */
    public function handle(Product $product, array $ids): Collection
    {
        $existing = ProductImage::query()->where('product_id', $product->id)->orderBy('id')->pluck('id')->all();
        $given = $ids;
        sort($given);

        if ($given !== $existing) {
            throw InvalidCatalogInput::field('ids', 'يجب إرسال كل صور المنتج مرة واحدة');
        }

        DB::transaction(function () use ($product, $ids): void {
            foreach ($ids as $index => $id) {
                ProductImage::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }

            $this->audit->log('product_image.reordered', $product, null, ['ids' => $ids]);
        });

        return $product->images()->get();
    }
}
