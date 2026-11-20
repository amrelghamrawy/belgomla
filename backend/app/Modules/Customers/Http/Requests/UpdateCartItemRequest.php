<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CartLineKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /api/store/cart/items/{sku_unit_id}: `{ quantity, kind? }` (BR-U05); `kind` names the order (default) or pre-order line. */
final class UpdateCartItemRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.AddCartItemRequest::MAX_QUANTITY],
            'kind' => ['sometimes', 'string', Rule::enum(CartLineKind::class)],
        ];
    }

    public function quantity(): int
    {
        return $this->integer('quantity');
    }

    public function kind(): CartLineKind
    {
        return $this->enum('kind', CartLineKind::class) ?? CartLineKind::Order;
    }
}
