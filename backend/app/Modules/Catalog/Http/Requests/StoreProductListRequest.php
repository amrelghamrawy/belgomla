<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Catalog\Concerns\ResolvesCustomer;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/store/products, /filters and /price-list: `category (slug, descendants included),
 * f[key]=<option_id|true|false>, q, sort=name|-created_at|price|-price` (08-api-contract.md §1).
 */
final class StoreProductListRequest extends PaginatedRequest
{
    use ReadsProductFilter;
    use ResolvesCustomer;

    public const array SORTS = ['name', '-created_at', 'price', '-price'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...$this->filterRules(),
            'status' => ['prohibited'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ];
    }

    protected function categoryBySlug(): bool
    {
        return true;
    }
}
