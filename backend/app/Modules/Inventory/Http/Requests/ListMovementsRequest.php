<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Http\PaginatedRequest;

/** GET /api/dashboard/inventory/{sku}/movements: `page, per_page`; always newest first. */
final class ListMovementsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
