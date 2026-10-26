<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Queries\CustomerAddressesQuery;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\CheckoutReport;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\CustomerCannotOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\PreorderDemandQuery;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Orders\Services\OrderSnapshots;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;

/**
 * Turns lines into a pending order (BR-PR06, 07-domain-model.md §12), whoever placed it: the customer's cart at
 * checkout, or a dashboard user's form (BR-O11). A step: it joins the caller's transaction (Checkout,
 * PlaceOrderForCustomer, PlacePreorder, ConvertPreorders), so any failure rolls back the caller's own writes too. Steps,
 * in the §12 order: tenant and customer active (the customer row locked) → address → lines → lock the stock rows in
 * sku_id order → re-check and re-price every line at the customer's own prices (PricingContextQuery, BR-PR14) → MOV (unless waived, D84) → order number → order and snapshotted
 * lines → reserve → history with the actor → the merchant's `new_order` notification (only for a customer's own order)
 * → audit.
 *
 * A pre-order (`kind = preorder`, BR-O14, D88) locks its products instead of stock, is checked by
 * CheckoutValidator::checkPreorder (pre-orders on, MOQ, the merchant's cap over every open pre-order, credit limit; no
 * MOV), reserves nothing (its lines are `reserved = false`) and rings `new_preorder`. A normal order converted from a
 * pre-order (`convertedFrom`, D88) keeps the pre-order's delivery snapshot and only needs its lines orderable now.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private TenantContext $tenants,
        private CustomerAddressesQuery $addresses,
        private StockLedger $ledger,
        private CheckoutValidator $validator,
        private PricingContextQuery $pricing,
        private PreorderDemandQuery $demand,
        private OrderNumbers $numbers,
        private OrderSnapshots $snapshots,
        private RecordStatusChange $history,
        private NotifyMerchants $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(OrderPlacementData $data): Order
    {
        $customer = $this->lockOrderingCustomer($data);
        $delivery = $this->delivery($customer, $data);

        if ($data->lines === []) {
            throw CheckoutFailed::emptyCart();
        }

        $pricing = $this->pricing->for($customer);
        $report = $data->kind === OrderKind::Preorder ? $this->checkPreorder($data, $customer, $pricing) : $this->checkOrder($data, $customer, $pricing);
        $checked = $data->convertedFrom instanceof Order ? $report->assertConvertible() : $report->assertPasses($data->skipMov);

        $order = $this->createOrder($customer, $delivery, $data, $checked);
        $this->history->handle($order, null, $data->placedByType, $data->placedById);

        if (! $data->byMerchant()) {
            // BR-O11: the merchant made it, no bell
            $this->notify->forOrder($data->kind === OrderKind::Preorder ? NotificationType::NewPreorder : NotificationType::NewOrder, $order);
        }

        $this->audit->log('order.created', $order, null, [
            'number' => $order->number,
            'status' => $order->status->value,
            'order_total' => $order->order_total,
            'lines' => count($checked),
            ...($data->kind === OrderKind::Preorder ? ['kind' => OrderKind::Preorder->value] : []),
            ...($data->convertedFrom instanceof Order ? ['converted_from' => $data->convertedFrom->number] : []),
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

    /**
     * The delivery snapshot (BR-O10): from one of the customer's active addresses, or the pre-order's own for a converted
     * order (D88).
     *
     * @return array{delivery_name: string, delivery_phone: string, delivery_address: array<string, string|null>}
     */
    private function delivery(Customer $customer, OrderPlacementData $data): array
    {
        if ($data->convertedFrom instanceof Order) {
            return $this->snapshots->deliveryOf($data->convertedFrom);
        }

        $address = $this->addresses->active($customer)->firstWhere('id', $data->addressId);

        return $address instanceof CustomerAddress ? $this->snapshots->delivery($customer, $address) : throw CheckoutFailed::addressUnavailable();
    }

    /** BR-INV01, BR-INV03: the stock rows locked in sku_id order, then every check against them. */
    private function checkOrder(OrderPlacementData $data, Customer $customer, ?PricingContext $pricing): CheckoutReport
    {
        $stock = $this->ledger->lock(array_map(fn (CartLineInput $line): int => $line->sku->id, $data->lines));

        return $this->validator->check($data->lines, $stock, $customer, $pricing);
    }

    /**
     * BR-O14: the products are locked in id order, so two pre-orders cannot both take the last units under the cap; the
     * lines are checked against the locked rows and what every open pre-order already holds of each SKU.
     */
    private function checkPreorder(OrderPlacementData $data, Customer $customer, ?PricingContext $pricing): CheckoutReport
    {
        $productIds = array_values(array_unique(array_map(fn (CartLineInput $line): int => $line->sku->product_id, $data->lines)));
        $products = Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($data->lines as $line) {
            $line->sku->setRelation('product', $products->get($line->sku->product_id) ?? $line->sku->product);
        }

        $open = $this->demand->openQuantities(array_map(fn (CartLineInput $line): int => $line->sku->id, $data->lines));

        return $this->validator->checkPreorder($data->lines, $customer, $open, $pricing);
    }

    /**
     * @param  array{delivery_name: string, delivery_phone: string, delivery_address: array<string, string|null>}  $delivery
     * @param  list<CheckoutLine>  $checked
     */
    private function createOrder(Customer $customer, array $delivery, OrderPlacementData $data, array $checked): Order
    {
        $total = array_reduce($checked, fn (string $sum, CheckoutLine $line): string => Money::add($sum, $line->price->lineTotal), '0.00');
        $reserves = $data->kind === OrderKind::Order;

        $order = Order::query()->create([
            'number' => $this->numbers->next(),
            'kind' => $data->kind,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'items_total' => $total,
            'order_total' => $total, // BR-O08: no fees, discounts or taxes in V1
            ...$delivery,
            'customer_note' => $data->note,
            'placed_by_type' => $data->placedByType,
            'placed_by_id' => $data->placedById,
            'converted_from_id' => $data->convertedFrom?->id,
            'placed_at' => now(),
        ]);

        foreach ($checked as $line) {
            $order->items()->create([...$this->snapshots->item($line), 'reserved' => $reserves]);

            if ($reserves) {
                $this->ledger->reserve($line->sku, $line->quantityBase()); // BR-INV03; a pre-order holds nothing (BR-INV01)
            }
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
