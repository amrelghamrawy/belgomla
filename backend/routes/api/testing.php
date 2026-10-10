<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\Testing\LastOtpController;
use Illuminate\Support\Facades\Route;

/*
| Test helpers for the Playwright suites (D62). Mounted under /api/_test with the tenant middleware, and only
| registered when the app runs in the local or testing environment (routes/api.php): never in production.
*/

Route::get('last-otp', [LastOtpController::class, 'show'])->name('last-otp');
