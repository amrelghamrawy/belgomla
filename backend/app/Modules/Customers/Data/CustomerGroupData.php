<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerGroupRequest;

/**
 * BR-PR12 (V2 Task 11): a customer group as the merchant creates or edits it. On create every field is set (the sort
 * order defaults to 0); on a PATCH a null field is left as it is, and `status` can only bring an archived group back.
 */
final readonly class CustomerGroupData
{
    public function __construct(
        public ?string $name,
        public ?string $discountPercent,
        public ?int $sortOrder,
        public ?CustomerGroupStatus $status = null,
    ) {}

    public static function fromRequest(StoreCustomerGroupRequest|UpdateCustomerGroupRequest $request): self
    {
        return new self(
            $request->name(),
            $request->discountPercent(),
            $request->sortOrder(),
            $request instanceof UpdateCustomerGroupRequest ? $request->status() : null,
        );
    }
}
