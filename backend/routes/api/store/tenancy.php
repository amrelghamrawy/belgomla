<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Store\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
