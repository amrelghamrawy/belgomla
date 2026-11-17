<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;

/**
 * A counted On Hand for one SKU, in base units, with the reason from the fixed list and the merchant's optional note
 * (BR-INV06 `adjustment`, D97), and the On Hand the merchant saw when the count began (D99 a; null = set as typed).
 */
final readonly class StockAdjustmentData
{
    public function __construct(
        public int $newOnHand,
        public AdjustmentReason $reason,
        public ?string $note = null,
        public ?int $expectedOnHand = null,
    ) {}

    public static function fromRequest(AdjustStockRequest $request): self
    {
        return new self($request->newOnHand(), $request->reason(), $request->note(), $request->expectedOnHand());
    }

    /** What the movement's `reason` reads: the reason, then the note when there is one («كسر أو تلف: وقع الكرتون»). */
    public function text(): string
    {
        return $this->note === null ? $this->reason->label() : "{$this->reason->label()}: {$this->note}";
    }
}
