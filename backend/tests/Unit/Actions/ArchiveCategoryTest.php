<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ArchiveCategory;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('ArchiveCategory archives once and is a no-op on an archived category', function (): void {
    $category = Category::factory()->create();

    app(ArchiveCategory::class)->handle($category);
    app(ArchiveCategory::class)->handle($category);

    expect($category->refresh()->status)->toBe(ArchiveStatus::Archived)
        ->and(AuditLog::query()->where('action', 'category.archived')->count())->toBe(1);
});
