<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\SetProductStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidProductTransition;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P04 an allowed transition is applied and audited with the batch id', function (): void {
    $product = Product::factory()->comingSoon()->create();

    app(SetProductStatus::class)->handle($product, ProductStatus::Active, '0199c6a4-5b2e-7000-8000-000000000001');

    $log = AuditLog::query()->sole();

    expect($product->refresh()->status)->toBe(ProductStatus::Active)
        ->and($log->action)->toBe('product.status_changed')
        ->and($log->batch_id)->toBe('0199c6a4-5b2e-7000-8000-000000000001')
        ->and($log->old_values)->toBe(['status' => 'coming_soon'])
        ->and($log->new_values)->toBe(['status' => 'active']);
});

test('BR-P04 a disallowed transition throws 409 invalid_transition and writes nothing', function (): void {
    $product = Product::factory()->create();

    try {
        app(SetProductStatus::class)->handle($product, ProductStatus::ComingSoon);
        $this->fail('Expected InvalidProductTransition');
    } catch (InvalidProductTransition $e) {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('invalid_transition')
            ->and($e->details)->toBe(['from' => 'active', 'to' => 'coming_soon']);
    }

    expect($product->refresh()->status)->toBe(ProductStatus::Active)
        ->and(AuditLog::query()->count())->toBe(0);
});
