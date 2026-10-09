<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ArchiveFieldDefinition;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('ArchiveFieldDefinition archives once', function (): void {
    $field = ProductFieldDefinition::factory()->create();

    app(ArchiveFieldDefinition::class)->handle($field);
    app(ArchiveFieldDefinition::class)->handle($field);

    expect($field->refresh()->status)->toBe(ArchiveStatus::Archived)
        ->and(AuditLog::query()->where('action', 'field.archived')->count())->toBe(1);
});
