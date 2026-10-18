<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\RecordReturnRequest;
use Carbon\CarbonImmutable;

/**
 * A return to record (BR-R01..R04): why, when the goods came back (null = now), the lines, and whether the merchant
 * confirms paying back in cash what the order was collected beyond its new net total.
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
    ) {}

    public static function fromRequest(RecordReturnRequest $request): self
    {
        return new self($request->reason(), $request->lines(), $request->returnedAt(), $request->refund());
    }
}
