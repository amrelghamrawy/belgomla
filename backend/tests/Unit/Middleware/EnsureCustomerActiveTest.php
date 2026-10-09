<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Http\Middleware\EnsureCustomerActive;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/_active', fn (): string => 'ok')->middleware([ResolveTenant::class, EnsureCustomerActive::class]);
});

test('an active customer passes', function (): void {
    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/_active')->assertOk();
});

test('a guest passes through to the auth middleware', function (): void {
    $this->tenantJson('GET', '/api/_active')->assertOk();
});

test('BR-CU05 a customer who is not active is refused with the status as the code', function (CustomerStatus $status): void {
    $this->actingAsCustomer($this->makeCustomer(['status' => $status]));

    $this->tenantJson('GET', '/api/_active')
        ->assertForbidden()
        ->assertJsonPath('error.code', $status->value);
})->with([
    'blocked' => [CustomerStatus::Blocked],
    'pending approval' => [CustomerStatus::PendingApproval],
    'pending verification' => [CustomerStatus::PendingVerification],
    'invited' => [CustomerStatus::Invited],
]);
