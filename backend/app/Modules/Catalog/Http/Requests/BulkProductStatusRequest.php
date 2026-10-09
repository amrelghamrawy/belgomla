<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/dashboard/products/bulk/status: `{ filter: {...same as list...}, status }` (BR-P12). */
final class BulkProductStatusRequest extends FormRequest
{
    use ReadsProductFilter;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['required', 'array'],
            ...$this->filterRules(),
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ];
    }

    public function status(): ProductStatus
    {
        return $this->enum('status', ProductStatus::class) ?? ProductStatus::Inactive;
    }

    protected function filterPrefix(): string
    {
        return 'filter.';
    }
}
