<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\BulkSetProductStatus;
use App\Modules\Catalog\Data\BulkStatusData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P12 changes every matching product and writes one batch: one row per product plus the bulk row', function (): void {
    $products = Product::factory()->count(3)->create(['name' => 'علوم']);
    Product::factory()->create(['name' => 'رياضيات']);

    $result = app(BulkSetProductStatus::class)->handle(new BulkStatusData(new ProductFilterData(q: 'علوم'), ProductStatus::Inactive));

    $logs = AuditLog::query()->where('batch_id', $result['batch_id'])->get();

    expect($result['affected'])->toBe(3)
        ->and($result['skipped'])->toBe(0)
        ->and(Product::query()->where('status', 'inactive')->pluck('id')->sort()->values()->all())->toBe($products->pluck('id')->all())
        ->and($logs)->toHaveCount(4)
        ->and($logs->firstWhere('action', 'product.bulk_status_changed')->new_values)
        ->toEqual(['status' => 'inactive', 'filter' => ['q' => 'علوم'], 'affected' => 3, 'skipped' => 0]);
});

test('D29 disallowed transitions are skipped, not failed', function (): void {
    Product::factory()->create(['name' => 'علوم']);
    Product::factory()->create(['name' => 'علوم', 'status' => ProductStatus::Inactive]);

    $result = app(BulkSetProductStatus::class)->handle(new BulkStatusData(new ProductFilterData(q: 'علوم'), ProductStatus::ComingSoon));

    expect($result['affected'])->toBe(0)
        ->and($result['skipped'])->toBe(2);
});

test('an empty filter is refused before anything changes', function (): void {
    Product::factory()->create();

    expect(fn () => app(BulkSetProductStatus::class)->handle(new BulkStatusData(new ProductFilterData, ProductStatus::Inactive)))
        ->toThrow(InvalidCatalogInput::class);

    expect(Product::query()->where('status', 'inactive')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
