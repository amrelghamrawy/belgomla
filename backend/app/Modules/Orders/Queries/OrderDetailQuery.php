<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Messaging\Queries\OrderMessagesQuery;
use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order with what its detail page shows (08-api-contract.md §3, §9): lines (removed ones too) and their changes
 * (BR-O12, BR-O13), the pre-order it came from or the orders it was converted into (BR-O14), status history and returns (live
 * ones for the customer, all for the merchant, BR-R05), plus the customer and its collection records (through OrderCollections, filled by Collections) for the dashboard.
 */
final readonly class OrderDetailQuery
{
    public function __construct(
        private OrderCollections $collections,
        private OrderMessagesQuery $messages,
    ) {}

    public function forStore(Order $order): Order
    {
        return $order->load(['items', 'itemChanges.orderItem', 'history', 'convertedFrom', 'conversions', 'returns' => fn (HasMany $returns): HasMany => $returns->whereNull('voided_at'), 'returns.items.orderItem']);
    }

    public function forDashboard(Order $order): Order
    {
        return $order->load(['items', 'itemChanges.orderItem', 'history', 'customer', 'convertedFrom', 'conversions', 'returns.items.orderItem']);
    }

    /**
     * BR-N03: the WhatsApp/SMS messages sent about the order, for the dashboard.
     *
     * @return list<array{purpose: string, purpose_label: string, channel: string, status: string, sent_at: string}>
     */
    public function messages(Order $order): array
    {
        return $this->messages->forOrder($order->id);
    }

    /**
     * @return list<JsonResource>
     */
    public function collections(Order $order): array
    {
        return $this->collections->forOrder($order);
    }
}
