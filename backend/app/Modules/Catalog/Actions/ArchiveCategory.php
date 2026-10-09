<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;

/**
 * BR-P06: a category is never hard-deleted. Archiving is allowed with products inside; they stay
 * linked and keep their own status (D31). The archived category and its subtree leave the store tree.
 */
final readonly class ArchiveCategory
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Category $category): Category
    {
        if ($category->status === ArchiveStatus::Archived) {
            return $category;
        }

        $category->update(['status' => ArchiveStatus::Archived]);

        $this->audit->log('category.archived', $category, ['status' => ArchiveStatus::Active->value], ['status' => ArchiveStatus::Archived->value]);

        return $category;
    }
}
