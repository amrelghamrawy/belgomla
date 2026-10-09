<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/dashboard/products: `q, category (id), status, f[key], sort, page, per_page`.
 * `low_stock=1` arrives with Inventory (Task 3).
 */
final class ListDashboardProductsRequest extends PaginatedRequest
{
    use ReadsProductFilter;

    public const array SORTS = ['name', '-name', 'created_at', '-created_at', 'price', '-price'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...$this->filterRules(),
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ];
    }
}
