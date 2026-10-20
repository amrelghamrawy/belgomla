<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Queries\CustomerAddressesQuery;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\CheckoutReport;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\CustomerCannotOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Orders\Services\OrderSnapshots;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;

/**
 * Turns lines into a pending order (BR-PR06, 07-domain-model.md §12), whoever placed it: the customer's cart at
 * checkout, or a dashboard user's form (BR-O11). A step: it joins the caller's transaction (Checkout,
 * PlaceOrderForCustomer), so any failure rolls back the caller's own writes too. Steps, in the §12 order: tenant and
 * customer active (the customer row locked) → address → lines → lock the stock rows in sku_id order → re-check and
 * re-price every line → MOV (unless waived, D84) → order number → order and snapshotted lines → reserve → history
 * with the actor → the merchant's `new_order` notification (only for a customer's own order) → audit.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private TenantContext $tenants,
        private CustomerAddressesQuery $addresses,
        private StockLedger $ledger,
        private CheckoutValidator $validator,
        private OrderNumbers $numbers,
        private OrderSnapshots $snapshots,
        private RecordStatusChange $history,
        private NotifyMerchants $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(OrderPlacementData $data): Order
    {
        $customer = $this->lockOrderingCustomer($data);
        $address = $this->address($customer, $data->addressId);

        if ($data->lines === []) {
            throw CheckoutFailed::emptyCart();
        }

        $stock = $this->ledger->lock(array_map(fn (CartLineInput $line): int => $line->sku->id, $data->lines));
        $report = $this->validator->check($data->lines, $stock, $customer);
        $checked = $report->assertPasses($data->skipMov);

        $order = $this->createOrder($customer, $address, $data, $checked);
        $this->history->handle($order, null, $data->placedByType, $data->placedById);

        if (! $data->byMerchant()) {
            $this->notify->forOrder(NotificationType::NewOrder, $order); // BR-O11: the merchant made it, no bell
        }

        $this->audit->log('order.created', $order, null, [
            'number' => $order->number,
            'status' => $order->status->value,
            'order_total' => $order->order_total,
            'lines' => count($checked),
            ...$this->merchantAudit($data, $report),
        ]);

        return $order;
    }

    /**
     * BR-PR06 step 1 and BR-CU05. Locked so two orders of one customer queue up and cannot both fit under the credit
     * limit (BR-C05). A customer checking out sees their own status as the code (403); a merchant ordering for a
     * customer who cannot order gets 409 (BR-O11).
     */
    private function lockOrderingCustomer(OrderPlacementData $data): Customer
    {
        if (! $this->tenants->get()->isActive()) {
            throw CheckoutFailed::storeUnavailable();
        }

        $customer = Customer::query()->lockForUpdate()->findOrFail($data->customer->id);
        $status = $customer->status;

        if ($status === CustomerStatus::Active) {
            return $customer;
        }

        throw $data->byMerchant() ? CustomerCannotOrder::because($status) : CheckoutFailed::customerNotActive($status);
    }

    private function address(Customer $customer, int $addressId): CustomerAddress
    {
        return $this->addresses->active($customer)->firstWhere('id', $addressId) ?? throw CheckoutFailed::addressUnavailable();
    }

    /**
     * @param  list<CheckoutLine>  $checked
     */
    private function createOrder(Customer $customer, CustomerAddress $address, OrderPlacementData $data, array $checked): Order
    {
        $total = array_reduce($checked, fn (string $sum, CheckoutLine $line): string => Money::add($sum, $line->price->lineTotal), '0.00');

        $order = Order::query()->create([
            'number' => $this->numbers->next(),
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'items_total' => $total,
            'order_total' => $total, // BR-O08: no fees, discounts or taxes in V1
            ...$this->snapshots->delivery($customer, $address),
            'customer_note' => $data->note,
            'placed_by_type' => $data->placedByType,
            'placed_by_id' => $data->placedById,
            'placed_at' => now(),
        ]);

        foreach ($checked as $line) {
            $order->items()->create($this->snapshots->item($line));
            $this->ledger->reserve($line->sku, $line->quantityBase()); // BR-INV03
        }

        return $order;
    }

    /**
     * BR-O11, D84: a merchant's order records who placed it, and whether the minimum order value was waived for it.
     *
     * @return array<string, mixed>
     */
    private function merchantAudit(OrderPlacementData $data, CheckoutReport $report): array
    {
        if (! $data->byMerchant()) {
            return [];
        }

        return [
            'placed_by' => ['type' => ActorType::TenantUser->value, 'id' => $data->placedById],
            ...($data->skipMov && $report->mov !== null ? ['mov_overridden' => $report->mov] : []),
        ];
    }
}
