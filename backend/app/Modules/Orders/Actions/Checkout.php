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
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/store/checkout: turns the customer's cart into a pending order (BR-PR06, 07-domain-model.md §12).
 * Owns its transaction: load the cart's order lines → PlaceOrder (every check, the order, the reservation, history, the
 * merchant's notification, audit) → clear those lines. Pre-order lines stay for their own confirmation (PlacePreorder, BR-O14). Any failure rolls all of it back, the cart included.
 */
final readonly class Checkout
{
    public function __construct(
        private CartQuery $cart,
        private PlaceOrder $place,
        private ClearCart $clearCart,
    ) {}

    public function handle(Customer $customer, CheckoutData $data): Order
    {
        return DB::transaction(function () use ($customer, $data): Order {
            $lines = array_values($this->cart->linesOf($customer, CartLineKind::Order)->map(fn (CartItem $line): CartLineInput => new CartLineInput($line->sku, $line->quantity))->all());

            $order = $this->place->handle(new OrderPlacementData($customer, $data->addressId, $lines, ActorType::Customer, $customer->id, $data->customerNote));
            $this->clearCart->handle($customer, CartLineKind::Order);

            return $order;
        });
    }
}
