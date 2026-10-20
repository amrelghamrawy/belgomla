<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Data\MerchantOrderData;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderQuoter;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders (BR-O11): a dashboard user places an order on behalf of an active customer, e.g. one
 * that arrived by phone. Owns its transaction: find the customer and the SKUs → PlaceOrder with the same checks as
 * checkout (pricing, MOQ, stock, credit limit; the minimum order value unless waived, D84) and the user as the actor
 * → the `placed` WhatsApp message when asked (sent after commit). No `new_order` notification: the merchant made it.
 */
final readonly class PlaceOrderForCustomer
{
    public function __construct(
        private OrderQuoter $quoter,
        private PlaceOrder $place,
        private NotifyCustomerOfStatus $notify,
    ) {}

    public function handle(MerchantOrderData $data, int $actorId): Order
    {
        return DB::transaction(function () use ($data, $actorId): Order {
            $customer = $this->quoter->customer($data->customerId);
            $lines = $this->quoter->lines($data->lines);

            $order = $this->place->handle(new OrderPlacementData($customer, $data->addressId, $lines, ActorType::TenantUser, $actorId, $data->note, $data->overrideMov));

            if ($data->notifyCustomer) {
                $this->notify->placed($order);
            }

            return $order;
        });
    }
}
