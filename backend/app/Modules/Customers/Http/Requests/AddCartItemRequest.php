<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/cart/items: `{ sku_id, quantity }`, a whole number of selling units (BR-U05). */
final class AddCartItemRequest extends FormRequest
{
    public const int MAX_QUANTITY = 100_000;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    public function skuId(): int
    {
        return $this->integer('sku_id');
    }

    public function quantity(): int
    {
        return $this->integer('quantity');
    }
}
