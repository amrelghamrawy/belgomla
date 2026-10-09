<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\RemoveProductRelation;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P10 deletes the relation and audits what it was', function (): void {
    $relation = ProductRelation::factory()->revisionOf()->create();

    app(RemoveProductRelation::class)->handle($relation);

    expect(ProductRelation::query()->count())->toBe(0)
        ->and(AuditLog::query()->sole()->old_values)->toEqual([
            'product_id' => $relation->product_id, 'related_product_id' => $relation->related_product_id, 'type' => 'revision_of',
        ]);
});
