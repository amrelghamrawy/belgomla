<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ProductPayloadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/products/{id}: replaces product and SKU fields. Status changes go through
 * PATCH .../status (state machine), stock through the inventory endpoints (Task 3).
 */
final class UpdateProductRequest extends FormRequest
{
    use ProductPayloadRules;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->productRules(),
            'status' => ['prohibited'],
        ];
    }
}
