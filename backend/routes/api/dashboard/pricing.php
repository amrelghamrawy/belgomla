<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\Dashboard\BulkPricingController;
use App\Modules\Pricing\Http\Controllers\Dashboard\BulkPricingPreviewController;
use App\Modules\Pricing\Http\Controllers\Dashboard\PricingRecomputeController;
use App\Modules\Pricing\Http\Controllers\Dashboard\PromotionCancelController;
use App\Modules\Pricing\Http\Controllers\Dashboard\PromotionController;
use App\Modules\Pricing\Http\Controllers\Dashboard\PromotionPreviewController;
use App\Modules\Pricing\Http\Controllers\Dashboard\SkuPricingController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard pricing (08-api-contract.md §6 "Pricing" and "Promotions", V2 Task 12). Mounted under /api/dashboard with the tenant middleware.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::put('products/{product}/units/{skuUnit}/pricing', [SkuPricingController::class, 'update'])->whereNumber(['product', 'skuUnit'])->middleware('permission:pricing.manage')->name('products.pricing.update');
    Route::post('products/{product}/pricing/recompute', [PricingRecomputeController::class, 'store'])->whereNumber('product')->middleware('permission:pricing.manage')->name('products.pricing.recompute');
    Route::post('pricing/bulk', [BulkPricingController::class, 'store'])->middleware('permission:pricing.manage')->name('pricing.bulk');
    Route::post('pricing/bulk/preview', [BulkPricingPreviewController::class, 'store'])->middleware('permission:pricing.manage')->name('pricing.bulk.preview');

    // BR-PR15 (V2 Task 12, D90)
    Route::get('promotions', [PromotionController::class, 'index'])->middleware('permission:pricing.manage')->name('promotions.index');
    Route::post('promotions', [PromotionController::class, 'store'])->middleware('idempotent')->middleware('permission:pricing.manage')->name('promotions.store');
    Route::post('promotions/preview', [PromotionPreviewController::class, 'store'])->middleware('permission:pricing.manage')->name('promotions.preview');
    Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->whereNumber('promotion')->middleware('permission:pricing.manage')->name('promotions.show');
    Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->whereNumber('promotion')->middleware('permission:pricing.manage')->name('promotions.update');
    Route::post('promotions/{promotion}/cancel', [PromotionCancelController::class, 'store'])->whereNumber('promotion')->middleware('permission:pricing.manage')->name('promotions.cancel');
});
