<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Http\Requests\QuoteOrderRequest;
use App\Modules\Orders\Http\Resources\OrderQuoteResource;
use App\Modules\Orders\Services\OrderQuoter;

final readonly class OrderQuoteController
{
    /** POST /api/dashboard/orders/quote — BR-O11: the checkout checks as a dry run, nothing saved */
    public function store(QuoteOrderRequest $request, OrderQuoter $quoter): OrderQuoteResource
    {
        return new OrderQuoteResource($quoter->quote($request->customerId(), $request->lines()));
    }
}
