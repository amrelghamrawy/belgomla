<?php

declare(strict_types=1);

namespace App\Modules\Collections\Data;

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Http\Requests\RecordCollectionRequest;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * BR-C02, BR-C10: an amount ("150.00"), when it was collected (null = now), an optional note, how it came in (a
 * recordable method, cash by default) and its optional reference (the transfer or wallet transaction number).
 */
final readonly class CollectionData
{
    public function __construct(
        public string $amount,
        public ?CarbonImmutable $collectedAt = null,
        public ?string $note = null,
        public CollectionMethod $method = CollectionMethod::Cash,
        public ?string $reference = null,
    ) {
        if (! in_array($method, CollectionMethod::recordable(), true)) {
            throw new InvalidArgumentException("A collection is recorded by a recordable method, not {$method->value}.");
        }
    }

    public static function fromRequest(RecordCollectionRequest $request): self
    {
        return new self($request->amount(), $request->collectedAt(), $request->note(), $request->paymentMethod(), $request->reference());
    }
}
