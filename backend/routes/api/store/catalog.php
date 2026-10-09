<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Store\CategoryController;
use App\Modules\Catalog\Http\Controllers\Store\FilterController;
use App\Modules\Catalog\Http\Controllers\Store\PriceListController;
use App\Modules\Catalog\Http\Controllers\Store\ProductController;
use Illuminate\Support\Facades\Route;

/*
| Storefront catalog (08-api-contract.md §1). Public; prices may be hidden per tenant setting.
*/

Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('filters', [FilterController::class, 'index'])->name('filters.index');
Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{slug}', [ProductController::class, 'show'])->where('slug', '[^/]{1,220}')->name('products.show');
Route::get('price-list', [PriceListController::class, 'index'])->name('price-list.index');
