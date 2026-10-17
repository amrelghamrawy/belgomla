<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:00', 'Africa/Cairo'));
});

test('tenant:demo creates a school-books store with its owner, the demo catalog and two months of activity', function (): void {
    $this->artisan('tenant:demo', ['subdomain' => 'Showroom', '--owner-email' => 'Owner@Showroom.test', '--owner-password' => 'secret123'])
        ->expectsOutputToContain('https://showroom.gomal.test')
        ->assertSuccessful();

    $tenant = Tenant::query()->where('subdomain', 'showroom')->sole();
    [$products, $customers, $orders] = $this->inTenant(fn (): array => [Product::query()->count(), Customer::query()->count(), Order::query()->count()], $tenant);

    expect($tenant->vertical)->toBe(Vertical::SchoolBooks)
        ->and($tenant->name)->toBe('مكتبة النور')
        ->and($products)->toBe(44)
        ->and($customers)->toBe(14)
        ->and($orders)->toBeGreaterThan(0);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'email' => 'owner@showroom.test', 'role' => 'owner']);
});

test('tenant:demo leaves the other stores untouched', function (): void {
    $this->artisan('tenant:demo', ['--owner-email' => 'owner@demo.test', '--owner-password' => 'secret123'])->assertSuccessful();

    [$products, $orders] = $this->inTenant(fn (): array => [Product::query()->count(), Order::query()->count()]);

    expect($products)->toBe(0)
        ->and($orders)->toBe(0);
});

test('tenant:demo defaults the subdomain to demo and takes the store name from --name', function (): void {
    $this->artisan('tenant:demo', ['--name' => 'مكتبة العرض', '--owner-email' => 'owner@demo.test', '--owner-password' => 'secret123'])
        ->expectsOutputToContain('https://demo.gomal.test')
        ->assertSuccessful();

    expect(Tenant::query()->where('subdomain', 'demo')->sole()->name)->toBe('مكتبة العرض');
});

test('tenant:demo asks for the owner email and password when they are not given', function (): void {
    $this->artisan('tenant:demo')
        ->expectsQuestion('Owner email', 'owner@demo.test')
        ->expectsQuestion('Owner password', 'secret123')
        ->expectsOutputToContain('Dashboard login: owner@demo.test')
        ->assertSuccessful();

    $this->assertDatabaseHas('tenant_users', ['email' => 'owner@demo.test', 'role' => 'owner']);
});

test('tenant:demo refuses an empty owner email', function (): void {
    $this->artisan('tenant:demo', ['--owner-password' => 'secret123'])
        ->expectsQuestion('Owner email', '')
        ->expectsOutputToContain('Owner email is required.')
        ->assertFailed();

    expect(Tenant::query()->where('subdomain', 'demo')->exists())->toBeFalse();
});

test('tenant:demo refuses an existing subdomain and adds nothing to that store', function (): void {
    $this->artisan('tenant:demo', ['subdomain' => 'alnoor', '--owner-email' => 'owner@demo.test', '--owner-password' => 'secret123'])
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(1)
        ->and($this->inTenant(fn (): int => Product::query()->count()))->toBe(0);
});

test('a failure while seeding leaves no demo tenant behind', function (): void {
    Order::creating(fn (): never => throw new RuntimeException('checkout failed'));

    expect(fn () => $this->artisan('tenant:demo', ['--owner-email' => 'owner@demo.test', '--owner-password' => 'secret123'])->run())
        ->toThrow(RuntimeException::class, 'checkout failed');

    expect(Tenant::query()->where('subdomain', 'demo')->exists())->toBeFalse();
    $this->assertDatabaseMissing('tenant_users', ['email' => 'owner@demo.test']);
});
