<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| PlaceOrder is the shared step behind Checkout (CheckoutTest) and PlaceOrderForCustomer (PlaceOrderForCustomerTest);
| this file covers what is its own: the caller's transaction and the actor.
*/

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->address = $this->addressOf($this->customer);
    $this->sku = $this->orderableSku();
    app(TenantContext::class)->set($this->tenant);

    $this->placement = fn (ActorType $by = ActorType::Customer): OrderPlacementData => new OrderPlacementData(
        $this->customer,
        $this->address->id,
        [new CartLineInput(app(CartSkuQuery::class)->find($this->sku->id), 5)],
        $by,
        $by === ActorType::Customer ? $this->customer->id : 77,
    );
});

test('BR-O11 a checkout is placed by the customer', function (): void {
    $order = DB::transaction(fn (): Order => app(PlaceOrder::class)->handle(($this->placement)()));

    expect($order->refresh()->only(['placed_by_type', 'placed_by_id']))->toBe(['placed_by_type' => ActorType::Customer, 'placed_by_id' => $this->customer->id]);
});

test('BR-O11 a merchant order is placed by the dashboard user', function (): void {
    $order = DB::transaction(fn (): Order => app(PlaceOrder::class)->handle(($this->placement)(ActorType::TenantUser)));

    expect($order->refresh()->only(['placed_by_type', 'placed_by_id']))->toBe(['placed_by_type' => ActorType::TenantUser, 'placed_by_id' => 77]);
});

test('BR-N02 BR-O11 only a customer\'s own order rings the merchant\'s bell', function (ActorType $by, int $notifications): void {
    DB::transaction(fn (): Order => app(PlaceOrder::class)->handle(($this->placement)($by)));

    expect(MerchantNotification::query()->where('type', NotificationType::NewOrder)->count())->toBe($notifications);
})->with(['the customer' => [ActorType::Customer, 1], 'a dashboard user' => [ActorType::TenantUser, 0]]);

test('D57 no lines is an empty cart', function (): void {
    $data = new OrderPlacementData($this->customer, $this->address->id, [], ActorType::Customer, $this->customer->id);

    expect(fn () => DB::transaction(fn (): Order => app(PlaceOrder::class)->handle($data)))->toThrow(function (CheckoutFailed $e): void {
        expect($e->errorCode)->toBe('cart_empty');
    });
});

test('it is a step: a failure of the caller after it rolls the order back', function (): void {
    try {
        DB::transaction(function (): void {
            app(PlaceOrder::class)->handle(($this->placement)());

            throw new RuntimeException('the caller failed');
        });
    } catch (RuntimeException) {
    }

    expect(Order::query()->count())->toBe(0);
});
