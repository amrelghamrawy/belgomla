<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CartLineKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/store/cart/items: `{ sku_unit_id | sku_id, quantity, kind? }`: the unit to buy in (D91; `sku_id` alone = the
 * SKU's default unit), a whole number of that unit (BR-U05); `kind` is `order`
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
            'sku_unit_id' => ['required_without:sku_id', 'nullable', 'integer', 'min:1'],
            'sku_id' => ['required_without:sku_unit_id', 'nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'kind' => ['sometimes', 'string', Rule::enum(CartLineKind::class)],
        ];
    }

    public function skuId(): ?int
    {
        return $this->filled('sku_id') ? $this->integer('sku_id') : null;
    }

    public function skuUnitId(): ?int
    {
        return $this->filled('sku_unit_id') ? $this->integer('sku_unit_id') : null;
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
