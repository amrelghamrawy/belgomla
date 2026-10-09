<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/store/cart/items/{sku_id}: `{ quantity }` (BR-U05). */
final class UpdateCartItemRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.AddCartItemRequest::MAX_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return $this->integer('quantity');
    }
}
