<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\OrderEditLineData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/orders/{number}/lines (BR-O12): `{ lines: [{ order_item_id?, sku_unit_id, quantity }], note? }`,
 * every line the order should have after the edit: an existing line by its `order_item_id` (its own SKU unit), a new one
 * without. Quantities in the line's unit like the new-order form (QuoteOrderRequest); a SKU unit once (D91).
 */
final class EditOrderLinesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.QuoteOrderRequest::MAX_LINES],
            'lines.*' => ['array:order_item_id,sku_unit_id,quantity'],
            'lines.*.order_item_id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'lines.*.sku_unit_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:250'],
        ];
    }

    /**
     * @return list<OrderEditLineData>
     */
    public function lines(): array
    {
        /** @var list<array{order_item_id?: int|string|null, sku_unit_id: int|string, quantity: int|string}> $lines */
        $lines = $this->array('lines');

        return array_map(fn (array $line): OrderEditLineData => new OrderEditLineData(
            isset($line['order_item_id']) ? (int) $line['order_item_id'] : null,
            (int) $line['sku_unit_id'],
            (int) $line['quantity'],
        ), $lines);
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    public function actorId(): int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : 0;
    }
}
