<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CartLineKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/store/cart/items: `{ sku_id, quantity, kind? }`, a whole number of selling units (BR-U05); `kind` is `order`
 * (default) or `preorder` for a Coming Soon product that takes pre-orders (BR-O14).
 */
final class AddCartItemRequest extends FormRequest
{
    public const int MAX_QUANTITY = 100_000;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'kind' => ['sometimes', 'string', Rule::enum(CartLineKind::class)],
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

    public function kind(): CartLineKind
    {
        return $this->enum('kind', CartLineKind::class) ?? CartLineKind::Order;
    }
}
