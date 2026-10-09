<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ArchiveFieldOption;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P08 ArchiveFieldOption archives once and keeps the row', function (): void {
    $option = ProductFieldOption::factory()->create();

    app(ArchiveFieldOption::class)->handle($option);
    app(ArchiveFieldOption::class)->handle($option);

    expect($option->refresh()->status)->toBe(ArchiveStatus::Archived)
        ->and(AuditLog::query()->where('action', 'field_option.archived')->count())->toBe(1);
});
