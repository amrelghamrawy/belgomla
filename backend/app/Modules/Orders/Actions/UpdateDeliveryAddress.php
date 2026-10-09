<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Data\DeliveryAddressData;
use App\Modules\Orders\Exceptions\AddressLocked;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/dashboard/orders/{number}/delivery-address (BR-O10, D9): the merchant corrects the delivery snapshot
 * while the order is not out for delivery yet; the customer's saved addresses are untouched. Owns its
 * transaction, locks the order and audits `order.delivery_address_changed` with the old and new snapshot.
 */
final readonly class UpdateDeliveryAddress
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Order $order, DeliveryAddressData $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->allowsAddressEdit()) {
                throw AddressLocked::at($order->status);
            }

            $old = $this->snapshot($order);

            $order->forceFill([
                'delivery_name' => $data->name ?? $order->delivery_name,
                'delivery_phone' => $data->phone ?? $order->delivery_phone,
                'delivery_address' => $data->address(),
            ])->save();

            $this->audit->log('order.delivery_address_changed', $order, $old, $this->snapshot($order));

            return $order;
        });
    }

    /**
     * @return array{delivery_name: string, delivery_phone: string, delivery_address: array<string, string|null>}
     */
    private function snapshot(Order $order): array
    {
        return [
            'delivery_name' => $order->delivery_name,
            'delivery_phone' => $order->delivery_phone,
            'delivery_address' => $order->delivery_address,
        ];
    }
}
