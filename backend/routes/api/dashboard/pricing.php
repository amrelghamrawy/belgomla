<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\Dashboard\BulkPricingController;
use App\Modules\Pricing\Http\Controllers\Dashboard\BulkPricingPreviewController;
use App\Modules\Pricing\Http\Controllers\Dashboard\PricingRecomputeController;
use App\Modules\Pricing\Http\Controllers\Dashboard\SkuPricingController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard pricing (08-api-contract.md §6 "Pricing"). Mounted under /api/dashboard with the tenant middleware.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::put('products/{product}/pricing', [SkuPricingController::class, 'update'])->whereNumber('product')->name('products.pricing.update');
    Route::post('products/{product}/pricing/recompute', [PricingRecomputeController::class, 'store'])->whereNumber('product')->name('products.pricing.recompute');
    Route::post('pricing/bulk', [BulkPricingController::class, 'store'])->name('pricing.bulk');
    Route::post('pricing/bulk/preview', [BulkPricingPreviewController::class, 'store'])->name('pricing.bulk.preview');
});
