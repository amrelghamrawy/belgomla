<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Http\Requests\AssignCustomersRequest;

/**
 * BR-PR12 (V2 Task 11): which customers join a group: the ids ticked on the customers list, or every customer the list's
 * filter selects (`search`, `status`, `groupId`; an empty filter is refused by the request).
 */
final readonly class GroupAssignmentData
{
    /**
     * @param  list<int>|null  $customerIds  null = by filter
     */
    public function __construct(
        public ?array $customerIds,
        public ?string $search = null,
        public ?CustomerStatus $status = null,
        public ?int $groupId = null,
    ) {}

    public static function fromRequest(AssignCustomersRequest $request): self
    {
        return new self($request->customerIds(), $request->search(), $request->status(), $request->groupId());
    }
}
