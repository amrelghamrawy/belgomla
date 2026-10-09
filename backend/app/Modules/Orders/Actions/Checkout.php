<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Actions\ClearCart;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Queries\CustomerAddressesQuery;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Orders\Services\OrderSnapshots;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/store/checkout: turns the customer's cart into a pending order (BR-PR06, 07-domain-model.md §12).
 * Owns its transaction and runs the steps in exactly the §12 order: tenant and customer active → address → cart
 * → lock the stock rows in sku_id order → re-check and re-price every line → MOV → order number → order and
 * snapshotted lines → reserve → history → clear the cart → notify the merchant → audit. Any failure rolls all
 * of it back, the cart included.
 */
final readonly class Checkout
{
    public function __construct(
        private TenantContext $tenants,
        private CustomerAddressesQuery $addresses,
        private CartQuery $cart,
        private StockLedger $ledger,
        private CheckoutValidator $validator,
        private OrderNumbers $numbers,
        private OrderSnapshots $snapshots,
        private RecordStatusChange $history,
        private ClearCart $clearCart,
        private NotifyMerchants $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Customer $customer, CheckoutData $data): Order
    {
        return DB::transaction(function () use ($customer, $data): Order {
            $this->assertCanOrder($customer);
            $address = $this->address($customer, $data->addressId);
            $lines = $this->lines($customer);

            $stock = $this->ledger->lock(array_values($lines->map(fn (CartItem $line): int => $line->sku_id)->all()));
            $checked = $this->validator->validate($lines, $stock);

            $order = $this->createOrder($customer, $address, $data, $checked);
            $this->history->handle($order, null, ActorType::Customer, $customer->id);
            $this->clearCart->handle($customer);
            $this->notify->handle(NotificationType::NewOrder, $order);
            $this->audit->log('order.created', $order, null, [
                'number' => $order->number,
                'status' => $order->status->value,
                'order_total' => $order->order_total,
                'lines' => count($checked),
            ]);

            return $order;
        });
    }

    private function assertCanOrder(Customer $customer): void
    {
        if (! $this->tenants->get()->isActive()) {
            throw CheckoutFailed::storeUnavailable();
        }

        $status = $customer->refresh()->status;

        if ($status !== CustomerStatus::Active) {
            throw CheckoutFailed::customerNotActive($status);
        }
    }

    private function address(Customer $customer, int $addressId): CustomerAddress
    {
        return $this->addresses->active($customer)->firstWhere('id', $addressId) ?? throw CheckoutFailed::addressUnavailable();
    }

    /**
     * @return Collection<int, CartItem>
     */
    private function lines(Customer $customer): Collection
    {
        $lines = $this->cart->linesOf($customer);

        if ($lines->isEmpty()) {
            throw CheckoutFailed::emptyCart();
        }

        return $lines;
    }

    /**
     * @param  list<CheckoutLine>  $checked
     */
    private function createOrder(Customer $customer, CustomerAddress $address, CheckoutData $data, array $checked): Order
    {
        $total = array_reduce($checked, fn (string $sum, CheckoutLine $line): string => Money::add($sum, $line->price->lineTotal), '0.00');

        $order = Order::query()->create([
            'number' => $this->numbers->next(),
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'items_total' => $total,
            'order_total' => $total, // BR-O08: no fees, discounts or taxes in V1
            ...$this->snapshots->delivery($customer, $address),
            'customer_note' => $data->customerNote,
            'placed_at' => now(),
        ]);

        foreach ($checked as $line) {
            $order->items()->create($this->snapshots->item($line));
            $this->ledger->reserve($line->sku, $line->quantityBase()); // BR-INV03
        }

        return $order;
    }
}
