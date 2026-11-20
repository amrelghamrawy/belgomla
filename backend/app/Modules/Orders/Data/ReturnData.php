<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Http\Requests\RecordReturnRequest;
use Carbon\CarbonImmutable;

/**
 * A return to record (BR-R01..R04): why, when the goods came back (null = now), the lines, and whether the merchant
 * confirms paying back what the order was collected beyond its new net total, and how: paid back by a method with an
 * optional reference (BR-C10) or kept as customer credit (BR-C07).
 */
final readonly class ReturnData
{
    /**
     * @param  list<ReturnLineData>  $lines
     */
    public function __construct(
        public string $reason,
        public array $lines,
        public ?CarbonImmutable $returnedAt = null,
        public bool $refund = false,
        public RefundMethod $refundTo = RefundMethod::Cash,
        public ?string $refundReference = null,
    ) {}

    public static function fromRequest(RecordReturnRequest $request): self
    {
        return new self($request->reason(), $request->lines(), $request->returnedAt(), $request->refund(), $request->refundTo(), $request->refundReference());
    }
}
