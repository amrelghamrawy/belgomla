<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\Dashboard\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard audit log (08-api-contract.md §12, BR-L): read-only; the trail of this tenant only.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
});
