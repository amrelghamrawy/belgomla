<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CartLineKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** DELETE /api/store/cart/items/{sku_unit_id}?kind=: the order line (default) or the pre-order line of the SKU unit (BR-O14, D91). */
final class RemoveCartItemRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::enum(CartLineKind::class)],
        ];
    }

    public function kind(): CartLineKind
    {
        return $this->enum('kind', CartLineKind::class) ?? CartLineKind::Order;
    }
}
