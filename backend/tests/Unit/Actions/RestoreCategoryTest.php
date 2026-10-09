<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\RestoreCategory;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('RestoreCategory restores once and is a no-op on an active category', function (): void {
    $category = Category::factory()->archived()->create();

    app(RestoreCategory::class)->handle($category);
    app(RestoreCategory::class)->handle($category);

    expect($category->refresh()->status)->toBe(ArchiveStatus::Active)
        ->and(AuditLog::query()->where('action', 'category.restored')->count())->toBe(1);
});
