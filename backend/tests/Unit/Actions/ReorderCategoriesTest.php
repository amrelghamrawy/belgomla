<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ReorderCategories;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('ReorderCategories updates every position and writes one audit record', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    app(ReorderCategories::class)->handle([$a->id => 3, $b->id => 1]);

    expect($a->refresh()->sort_order)->toBe(3)
        ->and($b->refresh()->sort_order)->toBe(1)
        ->and(AuditLog::query()->sole()->new_values)->toBe(['positions' => [$a->id => 3, $b->id => 1]]);
});

test('ReorderCategories rejects ids it cannot see', function (): void {
    $a = Category::factory()->create(['sort_order' => 1]);

    expect(fn () => app(ReorderCategories::class)->handle([$a->id => 5, 999_999 => 1]))->toThrow(InvalidCatalogInput::class);

    expect($a->refresh()->sort_order)->toBe(1);
});
