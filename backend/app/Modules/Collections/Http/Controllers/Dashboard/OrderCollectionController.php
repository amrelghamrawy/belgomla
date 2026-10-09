<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Http\Requests\RecordCollectionRequest;
use App\Modules\Collections\Http\Resources\CollectionResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\JsonResponse;

final readonly class OrderCollectionController
{
    /** POST /api/dashboard/orders/{number}/collections — BR-C02..C04 */
    public function store(RecordCollectionRequest $request, Order $order, RecordCollection $record): JsonResponse
    {
        return new CollectionResource($record->handle($order, CollectionData::fromRequest($request), $request->actorId()))
            ->response()
            ->setStatusCode(201);
    }
}
