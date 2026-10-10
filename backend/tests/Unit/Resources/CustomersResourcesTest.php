<?php

declare(strict_types=1);

use App\Modules\Customers\Data\PricedCart;
use App\Modules\Customers\Data\PricedCartLine;
use App\Modules\Customers\Http\Resources\AddressResource;
use App\Modules\Customers\Http\Resources\CartResource;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Http\Resources\InvitationResource;
use App\Modules\Customers\Http\Resources\InviteResultResource;
use App\Modules\Customers\Http\Resources\OtpSentResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Pricing\Data\ResolvedPrice;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->freezeTime();
});

test('BR-CU05 the storefront customer resource shows the profile and status only', function (): void {
    $customer = Customer::factory()->create(['name' => 'محمود', 'phone' => '+201012345678'])->refresh();

    expect(rendered(new CustomerResource($customer), storeRequest('/api/store/me')))->toBe([
        'id' => $customer->id,
        'name' => 'محمود',
        'phone' => '+201012345678',
        'status' => 'active',
        'status_label' => 'نشط',
        'phone_verified_at' => now()->toIso8601String(),
        'created_at' => now()->toIso8601String(),
    ]);
});

test('the dashboard customer resource adds the outstanding amount, creator, approver and block details', function (): void {
    $owner = $this->makeOwner();
    $customer = Customer::factory()->blocked()->create(['created_by' => $owner->id])->refresh();

    expect(array_keys(rendered(CustomerResource::forDashboard($customer, '12.50'), dashboardRequest())))->toBe([
        'id', 'name', 'phone', 'status', 'status_label', 'phone_verified_at', 'created_at',
        'outstanding_amount', 'created_by', 'approved_by', 'blocked_at', 'blocked_reason',
    ])
        ->and(rendered(CustomerResource::forDashboard($customer, '12.50'), dashboardRequest())['outstanding_amount'])->toBe('12.50');
});

test('the customer detail adds addresses when loaded and recent orders when given', function (): void {
    $customer = Customer::factory()->create();
    CustomerAddress::factory()->for($customer)->default()->create();
    $customer->load('addresses');

    $data = rendered(CustomerResource::forDashboard($customer, '0.00', []), dashboardRequest());

    expect($data['addresses'])->toHaveCount(1)
        ->and($data['recent_orders'])->toBe([]);
});

test('BR-CU03 the address resource key set', function (): void {
    $address = CustomerAddress::factory()->default()->create(['landmark' => 'بجوار البنك', 'phone' => '+201112345678'])->refresh();

    expect(rendered(new AddressResource($address), storeRequest()))->toBe([
        'id' => $address->id,
        'label' => 'المحل',
        'governorate' => 'القاهرة',
        'city' => 'مدينة نصر',
        'address_line' => '12 شارع عباس العقاد',
        'landmark' => 'بجوار البنك',
        'phone' => '+201112345678',
        'is_default' => true,
    ]);
});

test('BR-CU06 the cart resource matches the contract, money as strings', function (): void {
    $cart = new PricedCart(
        lines: [new PricedCartLine(501, 'علوم', 'olom', 'كرتونة', 20, 12, new ResolvedPrice('85.00', 10, null, null, '1020.00'), 5, 140)],
        removed: [['product_name' => 'قديم', 'reason' => 'inactive']],
        itemsTotal: '1020.00',
        movEnabled: true,
        movAmount: '1000.00',
        movSatisfied: true,
    );

    expect(rendered(new CartResource($cart), storeRequest('/api/store/cart')))->toBe([
        'items' => [[
            'sku_id' => 501, 'product_name' => 'علوم', 'product_slug' => 'olom', 'unit' => 'كرتونة', 'conversion_factor' => 20, 'quantity' => 12, 'unit_price' => '85.00',
            'tier_min_quantity' => 10, 'line_total' => '1020.00', 'moq' => 5, 'available' => 140,
        ]],
        'removed_items' => [['product_name' => 'قديم', 'reason' => 'inactive']],
        'items_total' => '1020.00',
        'mov' => ['enabled' => true, 'amount' => '1000.00', 'satisfied' => true],
    ]);
});

test('BR-CU08 the invitation page masks the phone', function (): void {
    $invitation = CustomerInvitation::factory()->for(Customer::factory()->invited()->state(['name' => 'مكتبة', 'phone' => '+201012345678']))->create()->load('customer');

    expect(rendered(new InvitationResource($invitation), storeRequest()))->toBe([
        'name' => 'مكتبة',
        'phone_masked' => '+20101****678',
        'expires_at' => now()->addDays(7)->toIso8601String(),
    ]);
});

test('BR-CU08 the invite result', function (): void {
    $invitation = CustomerInvitation::factory()->create(['sent_count' => 3]);

    expect(rendered(new InviteResultResource($invitation), dashboardRequest()))->toBe([
        'sent_via' => 'whatsapp',
        'expires_at' => now()->addDays(7)->toIso8601String(),
        'sent_count' => 3,
    ]);
});

test('BR-CU07 the otp sent resource names the channel', function (Channel $channel): void {
    expect(rendered(new OtpSentResource($channel), storeRequest()))->toBe(['otp_sent_via' => $channel->value]);
})->with([Channel::WhatsApp, Channel::Sms]);
