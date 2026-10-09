<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rules\Enum;

/** GET /api/dashboard/customers?q=&status=&page=&per_page= (08-api-contract.md §8). */
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
        ];
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
