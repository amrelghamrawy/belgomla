<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryReorderController;
use App\Modules\Catalog\Http\Controllers\Dashboard\CategoryRestoreController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldOptionArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\FieldOptionController;
use App\Modules\Catalog\Http\Controllers\Dashboard\UnitArchiveController;
use App\Modules\Catalog\Http\Controllers\Dashboard\UnitController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard catalog (08-api-contract.md §6). Mounted under /api/dashboard with the tenant middleware.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::post('categories/reorder', [CategoryReorderController::class, 'store'])->name('categories.reorder');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
    Route::post('categories/{category}/archive', [CategoryArchiveController::class, 'store'])->whereNumber('category')->name('categories.archive');
    Route::post('categories/{category}/restore', [CategoryRestoreController::class, 'store'])->whereNumber('category')->name('categories.restore');

    Route::get('units', [UnitController::class, 'index'])->name('units.index');
    Route::post('units', [UnitController::class, 'store'])->name('units.store');
    Route::put('units/{unit}', [UnitController::class, 'update'])->whereNumber('unit')->name('units.update');
    Route::post('units/{unit}/archive', [UnitArchiveController::class, 'store'])->whereNumber('unit')->name('units.archive');

    Route::get('fields', [FieldController::class, 'index'])->name('fields.index');
    Route::post('fields', [FieldController::class, 'store'])->name('fields.store');
    Route::put('fields/{field}', [FieldController::class, 'update'])->whereNumber('field')->name('fields.update');
    Route::post('fields/{field}/archive', [FieldArchiveController::class, 'store'])->whereNumber('field')->name('fields.archive');

    Route::scopeBindings()->group(function (): void {
        Route::post('fields/{field}/options', [FieldOptionController::class, 'store'])
            ->whereNumber('field')
            ->name('fields.options.store');
        Route::put('fields/{field}/options/{option}', [FieldOptionController::class, 'update'])
            ->whereNumber(['field', 'option'])
            ->name('fields.options.update');
        Route::post('fields/{field}/options/{option}/archive', [FieldOptionArchiveController::class, 'store'])
            ->whereNumber(['field', 'option'])
            ->name('fields.options.archive');
    });
});
