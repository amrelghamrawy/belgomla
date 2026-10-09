<?php

declare(strict_types=1);

use App\Modules\Customers\Services\CustomerSession;
use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->session = new Store('test', new ArraySessionHandler(10));
    $this->session->start();
});

test('Tech §6 starting a session logs the customer in on the customer guard, regenerates the id and binds the tenant', function (): void {
    $customer = $this->makeCustomer();
    $before = $this->session->getId();

    app(CustomerSession::class)->start($customer, $this->session);

    expect(Auth::guard('customer')->id())->toBe($customer->id)
        ->and(Auth::guard('tenant_user')->check())->toBeFalse()
        ->and($this->session->getId())->not->toBe($before)
        ->and($this->session->get(EnsureSessionTenant::SESSION_KEY))->toBe($this->tenant->id);
});

test('ending a session logs out and invalidates it', function (): void {
    $customer = $this->makeCustomer();
    $session = app(CustomerSession::class);
    $session->start($customer, $this->session);

    $session->end($this->session);

    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and($this->session->get(EnsureSessionTenant::SESSION_KEY))->toBeNull();
});
