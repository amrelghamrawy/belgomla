<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderQuoter;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders (BR-O11): a dashboard user places an order on behalf of an active customer, e.g. one
 * that arrived by phone. Owns its transaction: find the customer and the SKUs → PlaceOrder with the same checks as
 * checkout (pricing, MOQ, stock, credit limit; the minimum order value unless waived, D84) and the user as the actor
 * → the discounts given on the form (ApplyOrderDiscount, D85) → the `placed` WhatsApp message when asked (sent after
 * commit). No `new_order` notification: the merchant made it.
 */
final readonly class PlaceOrderForCustomer
{
    public function __construct(
        private OrderQuoter $quoter,
        private PlaceOrder $place,
        private ApplyOrderDiscount $discount,
        private NotifyCustomerOfStatus $notify,
    ) {}

    public function handle(MerchantOrderData $data, int $actorId): Order
    {
        return DB::transaction(function () use ($data, $actorId): Order {
            $customer = $this->quoter->customer($data->customerId);
            $lines = $this->quoter->lines($data->lines);

            $order = $this->place->handle(new OrderPlacementData($customer, $data->addressId, $lines, ActorType::TenantUser, $actorId, $data->note, $data->overrideMov));
            $order = $this->discount($order, $data, $actorId);

            if ($data->notifyCustomer) {
                $this->notify->placed($order);
            }

            return $order;
        });
    }

    /**
     * D85: the form's discounts apply once the order exists at list prices, so the minimum order value and the credit
     * limit were checked before the discount. One `order.discount_changed`, no `discount` message (`placed` carries
     * the total).
     */
    private function discount(Order $order, MerchantOrderData $data, int $actorId): Order
    {
        if ($data->lineDiscounts === [] && ! $data->discountType instanceof \App\Modules\Pricing\Enums\DiscountType) {
            return $order;
        }

        $lines = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get(['id', 'sku_id']) as $item) {
            $discount = $data->lineDiscounts[$item->sku_id] ?? null;

            if ($discount !== null) {
                $lines[] = new LineDiscountData($item->id, $discount['type'], $discount['value']);
            }
        }

        return $this->discount->handle($order, new DiscountData($lines, $data->discountType, $data->discountValue, $data->discountReason), $actorId, notifyCustomer: false);
    }
}
