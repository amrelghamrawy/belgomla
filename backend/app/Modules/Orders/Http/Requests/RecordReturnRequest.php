<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\ReturnLineData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/orders/{number}/returns: `{ reason, returned_at?, refund?, items: [{ order_item_id, quantity }] }`
 * (08-api-contract.md §9, BR-R01..R04). `returned_at` defaults to now and cannot be in the future; a line appears once.
 */
final class RecordReturnRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:300'],
            'returned_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'refund' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array:order_item_id,quantity'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function reason(): string
    {
        return mb_trim($this->string('reason')->value());
    }

    public function returnedAt(): ?CarbonImmutable
    {
        return $this->filled('returned_at') ? CarbonImmutable::parse($this->string('returned_at')->value())->utc() : null;
    }

    public function refund(): bool
    {
        return $this->boolean('refund');
    }

    /**
     * @return list<ReturnLineData>
     */
    public function lines(): array
    {
        /** @var list<array{order_item_id: int|string, quantity: int|string}> $items */
        $items = $this->array('items');

        return array_map(fn (array $item): ReturnLineData => new ReturnLineData((int) $item['order_item_id'], (int) $item['quantity']), $items);
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
