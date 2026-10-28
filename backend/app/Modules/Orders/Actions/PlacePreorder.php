<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Actions\ClearCart;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/store/checkout/preorder (BR-O14, D88): turns the pre-order lines of the customer's cart into a pre-order.
 * Owns its transaction: load the cart's pre-order lines → PlaceOrder with `kind = preorder` (the products locked, pre-orders
 * on, MOQ, the merchant's cap over every open pre-order, the credit limit; no stock check, no reservation, no minimum
 * order value) → clear those lines only. The prices are indicative: the pre-order is priced again when it is converted.
 */
final readonly class PlacePreorder
{
    public function __construct(
        private CartQuery $cart,
        private PlaceOrder $place,
        private ClearCart $clearCart,
    ) {}

    public function handle(Customer $customer, CheckoutData $data): Order
    {
        return DB::transaction(function () use ($customer, $data): Order {
            $lines = array_values($this->cart->linesOf($customer, CartLineKind::Preorder)->map(fn (CartItem $line): CartLineInput => new CartLineInput($line->skuUnit, $line->quantity))->all());

            $order = $this->place->handle(new OrderPlacementData($customer, $data->addressId, $lines, ActorType::Customer, $customer->id, $data->customerNote, kind: OrderKind::Preorder));
            $this->clearCart->handle($customer, CartLineKind::Preorder);

            return $order;
        });
    }
}
