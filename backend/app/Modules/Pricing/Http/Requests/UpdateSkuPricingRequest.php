<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Concerns\ReadsSkuPricing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/products/{id}/pricing: `{ cover_price?, base_price | base_price_percent, moq, tiers }`.
 * `tiers` replaces every tier (an empty list removes them); a missing `cover_price` keeps the current one.
 */
final class UpdateSkuPricingRequest extends FormRequest
{
    use ReadsSkuPricing;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->pricingRules(moqRequired: true, tiersRequired: true);
    }

    protected function keepsCoverPriceWhenAbsent(): bool
    {
        return true;
    }
}
