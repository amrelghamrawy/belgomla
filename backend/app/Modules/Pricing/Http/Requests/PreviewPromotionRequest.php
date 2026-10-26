<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Concerns\ReadsPromotion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/promotions/preview (BR-PR15, D90 d): `{ filter: {...} | sku_ids: [...], discount_percent? }` →
 * how many SKUs the promotion would cover and a sample, priced when a % is given. Writes nothing.
 */
final class PreviewPromotionRequest extends FormRequest
{
    use ReadsPromotion;

    public const int SAMPLE = 10;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = array_intersect_key($this->promotionRules(), array_flip(['filter', 'sku_ids', 'sku_ids.*', 'discount_percent', ...array_keys($this->filterRules())]));
        $rules['discount_percent'] = ['sometimes', 'nullable', 'decimal:0,2', 'gt:0', 'lt:90'];

        return $rules;
    }
}
