<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Store\CategoryController;
use Illuminate\Support\Facades\Route;

/*
| Storefront catalog (08-api-contract.md §1). Public; prices may be hidden per tenant setting.
*/

Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
