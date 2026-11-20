<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Concerns\ReadsPromotion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/promotions (BR-PR15, V2 Task 12, D90): `{ name, discount_percent (> 0, < 90), starts_at, ends_at,
 * filter: {...as bulk pricing...} | sku_ids: [...] (≤ 1000), status?: draft | scheduled }`. Times without an offset are
 * Cairo time (D60).
 */
final class StorePromotionRequest extends FormRequest
{
    use ReadsPromotion;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->promotionRules();
        $rules['ends_at'][] = 'after:starts_at';

        return $rules;
    }
}
