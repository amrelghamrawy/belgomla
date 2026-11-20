<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\EditOrderLinesRequest;

/**
 * The full list of lines an order should have after the merchant's edit (BR-O12): a live line left out is removed, a
 * line without an id is added, and a line whose quantity differs is changed. `note` goes to the history.
 */
final readonly class OrderEditData
{
    /**
     * @param  list<OrderEditLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $note = null,
    ) {}

    public static function fromRequest(EditOrderLinesRequest $request): self
    {
        return new self($request->lines(), $request->note());
    }
}
