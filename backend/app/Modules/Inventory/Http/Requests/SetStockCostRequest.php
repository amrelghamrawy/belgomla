<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/inventory/{sku}/cost (D96 d): `{ unit_cost, sku_unit_id? }`, money per the unit named (left out =
 * the base unit), stored as the average cost per base unit.
 */
final class SetStockCostRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'unit_cost' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'sku_unit_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function unitCost(): string
    {
        return Money::normalize($this->string('unit_cost')->value());
    }

    public function skuUnitId(): ?int
    {
        return $this->filled('sku_unit_id') ? $this->integer('sku_unit_id') : null;
    }
}
