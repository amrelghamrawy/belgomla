<?php

declare(strict_types=1);

namespace App\Modules\Collections\Data;

use App\Modules\Collections\Http\Requests\RecordCollectionRequest;
use Carbon\CarbonImmutable;

/** BR-C02: a cash amount ("150.00"), when it was collected (null = now) and an optional note. */
final readonly class CollectionData
{
    public function __construct(
        public string $amount,
        public ?CarbonImmutable $collectedAt = null,
        public ?string $note = null,
    ) {}

    public static function fromRequest(RecordCollectionRequest $request): self
    {
        return new self($request->amount(), $request->collectedAt(), $request->note());
    }
}
