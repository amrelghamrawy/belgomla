<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Concerns\ReadsPromotion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/dashboard/promotions/{id} (BR-PR15, D90 f): any of the create fields; a field left out stays. A new
 * `filter` or `sku_ids` replaces the target; `status` moves a draft to scheduled or back. The window is checked against
 * the stored times by UpdatePromotion.
 */
final class UpdatePromotionRequest extends FormRequest
{
    use ReadsPromotion;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->promotionRules() as $field => $fieldRules) {
            $rules[$field] = array_values(array_filter($fieldRules, fn (mixed $rule): bool => ! in_array($rule, ['required', 'required_without:sku_ids', 'required_without:filter'], true)));

            if (! str_starts_with($field, 'filter.') && ! str_contains($field, '*')) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }
}
