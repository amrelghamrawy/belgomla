<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Dashboard\BundleController;
use App\Modules\Catalog\Http\Controllers\Dashboard\BundleGenerateController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryReorderController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryRestoreController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldOptionArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldOptionController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ImportController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ImportPreviewController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ImportTemplateController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductBulkStatusController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductDuplicateController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductExportController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductImageController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductImageReorderController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductRelationController;
use App\Modules\Catalog\Http\Controllers\Dashboard\ProductStatusController;
use App\Modules\Catalog\Http\Controllers\Dashboard\UnitArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\UnitController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard catalog (08-api-contract.md §6). Mounted under /api/dashboard with the tenant middleware.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:orders.view')->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:catalog.manage')->name('categories.store');
    Route::post('categories/reorder', [CategoryReorderController::class, 'store'])->middleware('permission:catalog.manage')->name('categories.reorder');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->middleware('permission:catalog.manage')->name('categories.update');
    Route::post('categories/{category}/archive', [CategoryArchiveController::class, 'store'])->whereNumber('category')->middleware('permission:catalog.manage')->name('categories.archive');
    Route::post('categories/{category}/restore', [CategoryRestoreController::class, 'store'])->whereNumber('category')->middleware('permission:catalog.manage')->name('categories.restore');

    Route::get('units', [UnitController::class, 'index'])->middleware('permission:orders.view')->name('units.index');
    Route::post('units', [UnitController::class, 'store'])->middleware('permission:catalog.manage')->name('units.store');
    Route::put('units/{unit}', [UnitController::class, 'update'])->whereNumber('unit')->middleware('permission:catalog.manage')->name('units.update');
    Route::post('units/{unit}/archive', [UnitArchiveController::class, 'store'])->whereNumber('unit')->middleware('permission:catalog.manage')->name('units.archive');

    Route::get('fields', [FieldController::class, 'index'])->middleware('permission:orders.view')->name('fields.index');
    Route::post('fields', [FieldController::class, 'store'])->middleware('permission:catalog.manage')->name('fields.store');
    Route::put('fields/{field}', [FieldController::class, 'update'])->whereNumber('field')->middleware('permission:catalog.manage')->name('fields.update');
    Route::post('fields/{field}/archive', [FieldArchiveController::class, 'store'])->whereNumber('field')->middleware('permission:catalog.manage')->name('fields.archive');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:orders.view')->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:catalog.manage')->name('products.store');
    Route::post('products/bulk/status', [ProductBulkStatusController::class, 'store'])->middleware('permission:catalog.manage')->name('products.bulk-status');

    // V2 Task 15 (BR-P15, D93): bundles are products; these write them, the products endpoints read them.
    Route::post('bundles', [BundleController::class, 'store'])->middleware('permission:catalog.manage')->name('bundles.store');
    Route::post('bundles/generate', [BundleGenerateController::class, 'store'])->middleware('permission:catalog.manage')->name('bundles.generate');
    Route::put('bundles/{product}', [BundleController::class, 'update'])->whereNumber('product')->middleware('permission:catalog.manage')->name('bundles.update');

    // V2 Task 06: catalog export / import (the import is one run per previewed file, retry-safe).
    Route::get('products/export', [ProductExportController::class, 'index'])->middleware('permission:catalog.manage')->name('products.export');
    Route::get('products/export/{export}', [ProductExportController::class, 'show'])->whereUuid('export')->middleware('permission:catalog.manage')->name('products.export.download');
    Route::get('products/import/template', [ImportTemplateController::class, 'show'])->middleware('permission:catalog.manage')->name('products.import.template');
    Route::post('products/import/preview', [ImportPreviewController::class, 'store'])->middleware('permission:catalog.manage')->name('products.import.preview');
    Route::post('products/import', [ImportController::class, 'store'])->middleware('idempotent')->middleware('permission:catalog.manage')->name('products.import');
    Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->middleware('permission:catalog.manage')->name('imports.show');
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->middleware('permission:orders.view')->name('products.show');
    Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.update');
    Route::patch('products/{product}/status', [ProductStatusController::class, 'update'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.status');
    Route::post('products/{product}/duplicate', [ProductDuplicateController::class, 'store'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.duplicate');
    Route::post('products/{product}/images', [ProductImageController::class, 'store'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.images.store');
    Route::post('products/{product}/images/reorder', [ProductImageReorderController::class, 'store'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.images.reorder');
    Route::get('products/{product}/relations', [ProductRelationController::class, 'index'])->whereNumber('product')->middleware('permission:orders.view')->name('products.relations.index');
    Route::post('products/{product}/relations', [ProductRelationController::class, 'store'])->whereNumber('product')->middleware('permission:catalog.manage')->name('products.relations.store');

    Route::scopeBindings()->group(function (): void {
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
            ->whereNumber(['product', 'image'])
            ->middleware('permission:catalog.manage')->name('products.images.destroy');
        Route::delete('products/{product}/relations/{relation}', [ProductRelationController::class, 'destroy'])
            ->whereNumber(['product', 'relation'])
            ->middleware('permission:catalog.manage')->name('products.relations.destroy');

        Route::post('fields/{field}/options', [FieldOptionController::class, 'store'])
            ->whereNumber('field')
            ->middleware('permission:catalog.manage')->name('fields.options.store');
        Route::put('fields/{field}/options/{option}', [FieldOptionController::class, 'update'])
            ->whereNumber(['field', 'option'])
            ->middleware('permission:catalog.manage')->name('fields.options.update');
        Route::post('fields/{field}/options/{option}/archive', [FieldOptionArchiveController::class, 'store'])
            ->whereNumber(['field', 'option'])
            ->middleware('permission:catalog.manage')->name('fields.options.archive');
    });
});
