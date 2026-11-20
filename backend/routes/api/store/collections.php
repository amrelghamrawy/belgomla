<?php

declare(strict_types=1);

use App\Modules\Collections\Http\Controllers\Store\AccountStatementController;
use Illuminate\Support\Facades\Route;

/*
| Storefront account statement (08-api-contract.md §3, BR-C08): the signed-in customer's own ledger. Blocked and pending
| customers can still read what they owe, so it needs a session, not an active status.
*/

Route::middleware(['auth:customer', 'session.tenant:customer'])->group(function (): void {
    Route::get('account/statement', [AccountStatementController::class, 'show'])->name('account.statement');
});
