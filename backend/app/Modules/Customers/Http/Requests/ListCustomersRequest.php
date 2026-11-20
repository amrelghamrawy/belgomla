<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rules\Enum;

/** GET /api/dashboard/customers?q=&status=&group=&page=&per_page= (08-api-contract.md §8; `group` V2 Task 11). */
final class ListCustomersRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', new Enum(CustomerStatus::class)],
            'group' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** BR-PR12: only the members of this customer group. */
    public function groupId(): ?int
    {
        return $this->filled('group') ? $this->integer('group') : null;
    }

    public function search(): ?string
    {
        $q = mb_trim($this->string('q')->value());

        return $q === '' ? null : $q;
    }

    public function status(): ?CustomerStatus
    {
        return $this->enum('status', CustomerStatus::class);
    }
}
