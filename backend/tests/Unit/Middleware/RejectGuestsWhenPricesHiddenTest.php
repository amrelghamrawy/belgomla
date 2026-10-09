<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Middleware\RejectGuestsWhenPricesHidden;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/api/_prices', fn (): string => 'ok')->middleware([ResolveTenant::class, RejectGuestsWhenPricesHidden::class]);
});

test('D1 with public prices a guest passes', function (): void {
    $this->tenantJson('POST', '/api/_prices')->assertOk();
});

test('D53 with hidden prices a guest is refused with prices_hidden', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_login_for_prices' => true]);

    $this->tenantJson('POST', '/api/_prices')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'prices_hidden');
});

test('D1 with hidden prices a logged-in customer passes', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_login_for_prices' => true]);
    $this->actingAsCustomer();

    $this->tenantJson('POST', '/api/_prices')->assertOk();
});
