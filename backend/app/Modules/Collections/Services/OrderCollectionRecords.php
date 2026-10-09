<?php

declare(strict_types=1);

namespace App\Modules\Collections\Services;

use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Models\Order;

/**
 * Orders' OrderCollections: the collection records of one order, voided ones included, newest first, in the shape
 * of the collections list (without the customer, which the order detail already shows).
 */
final readonly class OrderCollectionRecords implements OrderCollections
{
    /**
     * @return list<CollectionResource>
     */
    public function forOrder(Order $order): array
    {
        return array_values(CashCollection::query()
            ->where('order_id', $order->id)
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CashCollection $collection): CollectionResource => new CollectionResource($collection->setRelation('order', $order->withoutRelations())))
            ->all());
    }
}
