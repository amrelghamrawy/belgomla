<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvitationInvalid;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Queries\CustomerAddressesQuery;
use App\Modules\Customers\Queries\CustomerDetailQuery;
use App\Modules\Customers\Queries\CustomerListQuery;
use App\Modules\Customers\Queries\InvitationByTokenQuery;
use App\Modules\Customers\Queries\OutstandingAmountsQuery;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU06 the cart query loads a customer\'s lines oldest first with what pricing needs', function (): void {
    $customer = Customer::factory()->create();
    $cart = Cart::factory()->for($customer)->create();
    $first = CartItem::factory()->for($cart)->create();
    $second = CartItem::factory()->for($cart)->create();
    CartItem::factory()->create();

    $lines = app(CartQuery::class)->linesOf($customer);

    expect($lines->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($lines[0]->relationLoaded('sku'))->toBeTrue()
        ->and(array_keys($lines[0]->sku->getRelations()))->toBe(['product', 'sellingUnit', 'tiers', 'inventory']);
});

test('the cart sku query loads the sku with its product, unit, tiers and stock', function (): void {
    $sku = Product::factory()->withSku(onHand: 5)->create()->sku()->sole();

    $found = app(CartSkuQuery::class)->find($sku->id);

    expect(array_keys($found->getRelations()))->toBe(['product', 'sellingUnit', 'tiers', 'inventory']);
});

test('BR-T04 the cart sku query does not find another tenant\'s sku', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create()->sku()->sole(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    app(CartSkuQuery::class)->find($foreign->id);
})->throws(ModelNotFoundException::class);

test('the customer list is newest first and filters by status', function (): void {
    $old = Customer::factory()->create();
    $new = Customer::factory()->pendingApproval()->create();

    expect(app(CustomerListQuery::class)->paginate(null, null, 10)->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and(app(CustomerListQuery::class)->paginate(null, CustomerStatus::PendingApproval, 10)->pluck('id')->all())->toBe([$new->id]);
});

test('the customer search matches the name case-insensitively or the phone digits', function (string $q, int $count): void {
    Customer::factory()->create(['name' => 'Al Amal Books', 'phone' => '+201012345678']);
    Customer::factory()->create(['name' => 'مكتبة النجاح', 'phone' => '+201198765432']);

    expect(app(CustomerListQuery::class)->paginate($q, null, 10)->total())->toBe($count);
})->with([
    'latin name, other case' => ['amal', 1],
    'arabic name' => ['النجاح', 1],
    'local phone' => ['01012345678', 1],
    'phone fragment' => ['98765', 1],
    'a like wildcard is literal' => ['%', 0],
    'nothing matches' => ['xyz', 0],
]);

test('the customer list is paginated', function (): void {
    Customer::factory()->count(3)->create();

    $page = app(CustomerListQuery::class)->paginate(null, null, 2);

    expect([$page->count(), $page->total(), $page->lastPage()])->toBe([2, 3, 2]);
});

test('the detail query loads only active addresses, default first', function (): void {
    $customer = Customer::factory()->create();
    $other = CustomerAddress::factory()->for($customer)->create();
    $default = CustomerAddress::factory()->for($customer)->default()->create();
    CustomerAddress::factory()->for($customer)->archived()->create();

    expect(app(CustomerDetailQuery::class)->get($customer)->addresses->pluck('id')->all())->toBe([$default->id, $other->id])
        ->and(app(CustomerDetailQuery::class)->recentOrders($customer))->toBe([]);
});

test('BR-CU03 the addresses query lists one customer\'s active addresses', function (): void {
    $customer = Customer::factory()->create();
    $mine = CustomerAddress::factory()->for($customer)->default()->create();
    CustomerAddress::factory()->for($customer)->create(['status' => AddressStatus::Archived]);
    CustomerAddress::factory()->create();

    expect(app(CustomerAddressesQuery::class)->active($customer)->pluck('id')->all())->toBe([$mine->id]);
});

test('BR-CU08 a live invitation is found by its token with the customer', function (): void {
    $invitation = CustomerInvitation::factory()->token('tok')->create();

    $found = app(InvitationByTokenQuery::class)->live('tok');

    expect($found->is($invitation))->toBeTrue()
        ->and($found->relationLoaded('customer'))->toBeTrue();
});

test('BR-CU08 dead invitations are not found', function (Closure $invitation): void {
    $invitation();

    app(InvitationByTokenQuery::class)->live('tok');
})->with([
    'used' => [fn () => CustomerInvitation::factory()->token('tok')->used()->create()],
    'expired' => [fn () => CustomerInvitation::factory()->token('tok')->expired()->create()],
    'customer no longer invited' => [fn () => CustomerInvitation::factory()->token('tok')->for(Customer::factory())->create()],
    'another token' => [fn () => CustomerInvitation::factory()->token('other')->create()],
])->throws(InvitationInvalid::class);

test('outstanding amounts come from the contract, missing customers owe 0.00', function (): void {
    $customer = Customer::factory()->create();
    app()->instance(OutstandingBalances::class, new readonly class implements OutstandingBalances
    {
        public function forCustomers(array $customerIds): array
        {
            return [999 => '5.00'];
        }
    });

    expect(app(OutstandingAmountsQuery::class)->of($customer))->toBe('0.00')
        ->and(app(OutstandingAmountsQuery::class)->ofPage(app(CustomerListQuery::class)->paginate(null, null, 10)))->toBe([999 => '5.00']);
});

test('D2 BR-MOV01 the settings query reads the approval flag and the mov', function (bool $approval, bool $movEnabled, ?string $movAmount, array $mov): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => $approval, 'mov_enabled' => $movEnabled, 'mov_amount' => $movAmount]);

    expect(app(CurrentTenantSettingsQuery::class)->requiresCustomerApproval())->toBe($approval)
        ->and(app(CurrentTenantSettingsQuery::class)->minimumOrderValue())->toBe($mov);
})->with([
    'defaults' => [false, false, null, ['enabled' => false, 'amount' => null]],
    'approval on, mov on' => [true, true, '1000.00', ['enabled' => true, 'amount' => '1000.00']],
    'mov off keeps no amount' => [false, false, '1000.00', ['enabled' => false, 'amount' => null]],
    'mov on without amount' => [false, true, null, ['enabled' => false, 'amount' => null]],
]);
