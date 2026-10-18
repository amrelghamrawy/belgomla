<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Http\Requests\RecordReturnRequest;
use App\Modules\Orders\Http\Resources\OrderReturnResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\ReturnsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** /api/dashboard/orders/{number}/returns — the returns of one order (BR-R01..R05). */
final readonly class OrderReturnController
{
    /** GET: the order's returns oldest first, voided ones included, plus `returnable` per line (BR-R02). */
    public function index(Order $order, ReturnsQuery $returns): AnonymousResourceCollection
    {
        return OrderReturnResource::collection($returns->forOrder($order))->additional(['returnable' => $returns->returnable($order)]);
    }

    /** POST: records a return — BR-R01..R04, D74 */
    public function store(RecordReturnRequest $request, Order $order, RecordReturn $record): JsonResponse
    {
        return new OrderReturnResource($record->handle($order, ReturnData::fromRequest($request), $request->actorId()))
            ->response()
            ->setStatusCode(201);
    }
}
