<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Support\Http\PaginatedRequest;

/** GET /api/dashboard/customers/{id}/prices?q=&page=&per_page= (BR-PR13, V2 Task 11): `q` matches the product or SKU code. */
final class ListCustomerPricesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function search(): ?string
    {
        $q = mb_trim($this->string('q')->value());

        return $q === '' ? null : $q;
    }
}
