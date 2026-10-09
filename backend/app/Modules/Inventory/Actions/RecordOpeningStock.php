<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Services\StockLedger;

/**
 * The opening stock of a new SKU (08-api-contract.md §6 `opening_stock`), written as an `opening` movement
 * (BR-INV06); zero only creates the empty stock row (a duplicated product, D30). Called by Catalog's
 * CreateProduct and DuplicateProduct and joins their transaction; their `product.*` audit record carries
 * the quantity, so nothing is audited here.
 */
final readonly class RecordOpeningStock
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(Sku $sku, int $quantity, ?int $actorId = null): void
    {
        $this->ledger->openingStock($sku, $quantity, $actorId);
    }
}
