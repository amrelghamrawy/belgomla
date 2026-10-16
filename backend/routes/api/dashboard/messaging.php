<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\Dashboard\StoreWhatsAppController;
use Illuminate\Support\Facades\Route;

/*
| The store's own WhatsApp number (D70, tasks/12): status and QR, link, unlink.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('settings/whatsapp', [StoreWhatsAppController::class, 'show'])->name('settings.whatsapp.show');
    Route::post('settings/whatsapp', [StoreWhatsAppController::class, 'store'])->name('settings.whatsapp.store');
    Route::delete('settings/whatsapp', [StoreWhatsAppController::class, 'destroy'])->name('settings.whatsapp.destroy');
});
