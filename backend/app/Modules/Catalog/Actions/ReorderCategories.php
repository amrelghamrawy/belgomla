<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;

/** Sets sort_order for several categories at once; one audit record. Owns its transaction. */
final readonly class ReorderCategories
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<int, int>  $positions  category id => sort order
     */
    public function handle(array $positions): void
    {
        $categories = Category::query()->whereKey(array_keys($positions))->get();

        if ($categories->count() !== count($positions)) {
            throw InvalidCatalogInput::unknownReference('id');
        }

        DB::transaction(function () use ($categories, $positions): void {
            foreach ($categories as $category) {
                $category->update(['sort_order' => $positions[$category->id]]);
            }

            $this->audit->log('category.reordered', 'categories', null, ['positions' => $positions]);
        });
    }
}
