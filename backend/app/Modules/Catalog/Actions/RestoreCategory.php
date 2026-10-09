<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;

final readonly class RestoreCategory
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Category $category): Category
    {
        if ($category->status === ArchiveStatus::Active) {
            return $category;
        }

        $category->update(['status' => ArchiveStatus::Active]);

        $this->audit->log('category.restored', $category, ['status' => ArchiveStatus::Archived->value], ['status' => ArchiveStatus::Active->value]);

        return $category;
    }
}
