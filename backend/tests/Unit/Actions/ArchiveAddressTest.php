<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\ArchiveAddress;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
});

test('BR-CU03 archiving the default hands it to the oldest remaining address', function (): void {
    $default = CustomerAddress::factory()->for($this->customer)->default()->create();
    $oldest = CustomerAddress::factory()->for($this->customer)->create();
    CustomerAddress::factory()->for($this->customer)->create();

    app(ArchiveAddress::class)->handle($default);

    expect($default->refresh()->only(['status', 'is_default']))->toBe(['status' => AddressStatus::Archived, 'is_default' => false])
        ->and($oldest->refresh()->is_default)->toBeTrue();
});

test('archiving another address leaves the default alone', function (): void {
    $default = CustomerAddress::factory()->for($this->customer)->default()->create();
    $other = CustomerAddress::factory()->for($this->customer)->create();

    app(ArchiveAddress::class)->handle($other);

    expect($default->refresh()->is_default)->toBeTrue();
});

test('archiving the last address leaves no default', function (): void {
    $only = CustomerAddress::factory()->for($this->customer)->default()->create();

    app(ArchiveAddress::class)->handle($only);

    expect(CustomerAddress::query()->where('is_default', true)->count())->toBe(0);
});
