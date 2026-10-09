<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;

/**
 * A merchant moves an order forward (BR-O01). `collectFullAmount` is the "delivered and collected" shortcut
 * (BR-C04) and only counts when the target is `delivered`.
 */
final readonly class TransitionData
{
    public function __construct(
        public OrderStatus $to,
        public ?string $note = null,
        public bool $collectFullAmount = false,
    ) {}

    public static function fromRequest(TransitionOrderRequest $request): self
    {
        $to = $request->to();

        return new self($to, $request->note(), $to === OrderStatus::Delivered && $request->collectFullAmount());
    }
}
