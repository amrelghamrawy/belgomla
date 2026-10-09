<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

/** OrderCollections without the Collections module: an order lists no collections. */
final readonly class NullOrderCollections implements OrderCollections
{
    /**
     * @return list<JsonResource>
     */
    public function forOrder(Order $order): array
    {
        return [];
    }
}
