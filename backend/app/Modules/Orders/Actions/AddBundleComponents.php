<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderSnapshots;

/**
 * BR-P15 (D93): writes the component children of a bundle line, its current unit price (after any manual discount)
 * split over them (OrderSnapshots::components), each reserving its own base units (BR-INV03) unless the line holds no
 * stock. A step of AddOrderLine and of EditOrderLines (a bundle line whose quantity changed gets new children). Joins the
 * caller's transaction, on stock rows the caller locked.
 */
final readonly class AddBundleComponents
{
    public function __construct(
        private StockLedger $ledger,
        private OrderSnapshots $snapshots,
    ) {}

    public function handle(OrderItem $bundle, CheckoutLine $line, bool $reserves = true): void
    {
        foreach ($this->snapshots->components($line, $bundle->unit_price) as $index => $row) {
            OrderItem::query()->create([...$row, 'order_id' => $bundle->order_id, 'parent_item_id' => $bundle->id, 'reserved' => $reserves]);

            if ($reserves) {
                $this->ledger->reserve($line->components[$index]->sku, (int) $row['quantity_base']);
            }
        }
    }
}
