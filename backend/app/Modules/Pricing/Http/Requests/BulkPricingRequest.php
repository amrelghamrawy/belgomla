<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Pricing\Concerns\ReadsSkuPricing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/pricing/bulk and .../bulk/preview (BR-PR11):
 * `{ filter: {...same as the product list...}, base_price_percent, tiers: [{ min_quantity, discount_percent }], moq? }`.
 * Prices across many cover prices only make sense as percents, so absolute prices are not accepted here.
 */
final class BulkPricingRequest extends FormRequest
{
    use ReadsProductFilter;
    use ReadsSkuPricing;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['required', 'array'],
            ...$this->filterRules(),
            'base_price_percent' => ['required', 'decimal:0,2', 'gt:0', 'lt:100'],
            'tiers' => ['present', 'array', 'max:20'],
            'tiers.*' => ['array'],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.discount_percent' => ['required', 'decimal:0,2', 'gt:0', 'lt:100'],
            'moq' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function basePricePercent(): string
    {
        return $this->pricingInput()['basePricePercent'] ?? '0.00';
    }

    public function moq(): ?int
    {
        return $this->filled('moq') ? $this->integer('moq') : null;
    }

    protected function filterPrefix(): string
    {
        return 'filter.';
    }
}
