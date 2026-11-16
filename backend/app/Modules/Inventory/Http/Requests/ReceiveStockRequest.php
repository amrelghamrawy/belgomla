<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/inventory/{sku}/receive (BR-INV09, D96 b): `{ quantity, unit_cost, sku_unit_id?, note? }`,
 * the quantity in whole units of the unit named (left out = the base unit), the cost money per that unit.
 */
final class ReceiveStockRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.AdjustStockRequest::MAX_ON_HAND],
            'unit_cost' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'sku_unit_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function quantity(): int
    {
        return $this->integer('quantity');
    }

    public function unitCost(): string
    {
        return Money::normalize($this->string('unit_cost')->value());
    }

    public function skuUnitId(): ?int
    {
        return $this->filled('sku_unit_id') ? $this->integer('sku_unit_id') : null;
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    /** The dashboard user receiving (inventory_movements.actor_id). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
