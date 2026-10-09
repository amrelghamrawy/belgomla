<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Queries\InventoryListQuery;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rule;

/** GET /api/dashboard/inventory: `q, low_stock, sort, page, per_page`. */
final class ListInventoryRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'low_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(InventoryListQuery::SORTS)],
        ];
    }

    public function q(): ?string
    {
        $q = mb_trim($this->string('q')->value());

        return $q === '' ? null : $q;
    }

    public function lowStock(): bool
    {
        return $this->boolean('low_stock');
    }
}
