<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * POST /api/dashboard/customer-groups/{id}/assign (BR-PR12, V2 Task 11): `{ customer_ids: [..] }` (the ticked customers, at
 * most 500) or `{ filter: { q?, status?, group? } }` (every customer the list's filter selects; an empty filter is refused,
 * like bulk product status, BR-P12). One of the two.
 */
final class AssignCustomersRequest extends FormRequest
{
    public const int MAX_IDS = 500;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'customer_ids' => ['required_without:filter', 'prohibits:filter', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'customer_ids.*' => ['integer', 'min:1'],
            'filter' => ['required_without:customer_ids', 'array'],
            'filter.q' => ['exclude_without:filter', 'nullable', 'string', 'max:100', 'required_without_all:filter.status,filter.group'],
            'filter.status' => ['sometimes', 'string', new Enum(CustomerStatus::class)],
            'filter.group' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<int>|null null = by filter
     */
    public function customerIds(): ?array
    {
        if (! $this->has('customer_ids')) {
            return null;
        }

        /** @var list<int|string> $ids */
        $ids = $this->validated('customer_ids');

        return array_map(intval(...), $ids);
    }

    public function search(): ?string
    {
        $q = mb_trim($this->string('filter.q')->value());

        return $q === '' ? null : $q;
    }

    public function status(): ?CustomerStatus
    {
        return $this->enum('filter.status', CustomerStatus::class);
    }

    public function groupId(): ?int
    {
        return $this->filled('filter.group') ? $this->integer('filter.group') : null;
    }
}
