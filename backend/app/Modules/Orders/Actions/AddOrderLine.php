<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderSnapshots;

/**
 * Writes one checked line into an order (BR-PR07) and reserves its stock (BR-INV03), the shared step of PlaceOrder and
 * EditOrderLines. A bundle line (BR-P15, V2 Task 15, D93) is written as the bundle (`is_bundle`, holding nothing) and its
 * component children (AddBundleComponents), each reserving its own base units. A pre-order line (`$reserves` false,
 * BR-O14) reserves nothing. Joins the caller's transaction, on stock rows the caller locked.
 */
final readonly class AddOrderLine
{
    public function __construct(
        private StockLedger $ledger,
        private OrderSnapshots $snapshots,
        private AddBundleComponents $components,
    ) {}

    public function handle(Order $order, CheckoutLine $line, bool $reserves = true): OrderItem
    {
        if (! $line->isBundle()) {
            $item = $order->items()->create([...$this->snapshots->item($line), 'reserved' => $reserves]);

            if ($reserves) {
                $this->ledger->reserve($line->sku, $line->quantityBase());
            }

            return $item;
        }

        $bundle = $order->items()->create([...$this->snapshots->item($line), 'is_bundle' => true, 'reserved' => false]);
        $this->components->handle($bundle, $line, $reserves);

        return $bundle;
    }
}
