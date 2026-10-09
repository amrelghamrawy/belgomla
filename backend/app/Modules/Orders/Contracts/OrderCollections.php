<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The collection records of an order for the dashboard order detail (08-api-contract.md §9, BR-C02), as the
 * resources that render them. Implemented by Collections (OrderCollectionRecords, bound in
 * CollectionsServiceProvider), so Orders never depends on Collections; NullOrderCollections lists none.
 */
interface OrderCollections
{
    /**
     * @return list<JsonResource>
     */
    public function forOrder(Order $order): array;
}
