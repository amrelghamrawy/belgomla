<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\CancelOrderRequest;

/** BR-O02: a cancellation always carries a free-text reason. */
final readonly class CancelData
{
    public function __construct(public string $reason) {}

    public static function fromRequest(CancelOrderRequest $request): self
    {
        return new self($request->reason());
    }
}
