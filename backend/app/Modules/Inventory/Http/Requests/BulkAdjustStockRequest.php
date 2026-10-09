<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/inventory/bulk-adjust: `[ { sku_id, new_on_hand, reason } ]`, each SKU once. */
final class BulkAdjustStockRequest extends FormRequest
{
    public const int MAX_ITEMS = 500;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.sku_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.new_on_hand' => ['required', 'integer', 'min:0', 'max:'.AdjustStockRequest::MAX_ON_HAND],
            'items.*.reason' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return list<array{sku_id: int, new_on_hand: int, reason: string}>
     */
    public function items(): array
    {
        $items = [];

        foreach (array_keys($this->array('items')) as $index) {
            $items[] = [
                'sku_id' => $this->integer("items.{$index}.sku_id"),
                'new_on_hand' => $this->integer("items.{$index}.new_on_hand"),
                'reason' => mb_trim($this->string("items.{$index}.reason")->value()),
            ];
        }

        return $items;
    }

    /** The dashboard user adjusting (inventory_movements.actor_id). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    /**
     * The contract's body is a bare JSON array; validate it as `items` so errors point at `items.0.sku_id`.
     */
    protected function prepareForValidation(): void
    {
        $body = json_decode($this->getContent(), true);

        if (is_array($body) && array_is_list($body)) {
            $this->replace(['items' => $body]);
        }
    }
}
