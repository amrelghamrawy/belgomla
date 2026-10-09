<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an image row and its file. Removing the main image promotes the first remaining image.
 * The file is deleted only after the transaction commits.
 */
final readonly class RemoveProductImage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $image->delete();

            if ($image->is_main) {
                ProductImage::query()
                    ->where('product_id', $image->product_id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_main' => true]);
            }

            $this->audit->log('product_image.removed', $image, ['product_id' => $image->product_id, 'path' => $image->path, 'is_main' => $image->is_main]);
        });

        Storage::disk('public')->delete($image->path);
    }
}
