<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;

/**
 * A merchant moves an order forward (BR-O01). `collectFullAmount` is the "delivered and collected" shortcut
 * (BR-C04) and `deliveredQuantities` the partial delivery (order item id => delivered selling units, a line left out
 * went out in full, BR-O13); both only count when the target is `delivered`.
 */
final readonly class TransitionData
{
    /**
     * @param  array<int, int>|null  $deliveredQuantities
     */
    public function __construct(
        public OrderStatus $to,
        public ?string $note = null,
        public bool $collectFullAmount = false,
        public ?array $deliveredQuantities = null,
    ) {}

    public static function fromRequest(TransitionOrderRequest $request): self
    {
        $to = $request->to();

        $delivered = $to === OrderStatus::Delivered;

        return new self($to, $request->note(), $delivered && $request->collectFullAmount(), $delivered ? $request->deliveredQuantities() : null);
    }
}
