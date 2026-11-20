<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Enums\RefundMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/orders/{number}/returns: `{ reason, returned_at?, refund?, refund_to?, refund_reference?, items: [{ order_item_id, quantity }] }`
 * (08-api-contract.md §9, BR-R01..R04); `refund_to` is `cash` (default), `instapay`, `wallet`, `bank_transfer`
 * (BR-C10) or `credit` (BR-C07); `refund_reference` (≤ 60) only when paid back. `returned_at` defaults to now and
 * cannot be in the future; a line appears once.
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
            'refund_to' => ['sometimes', 'string', 'in:'.implode(',', array_column(RefundMethod::cases(), 'value'))],
            'refund_reference' => ['nullable', 'string', 'max:60', 'prohibited_if:refund_to,credit'],
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

    public function refundTo(): RefundMethod
    {
        return $this->enum('refund_to', RefundMethod::class) ?? RefundMethod::Cash;
    }

    public function refundReference(): ?string
    {
        $reference = mb_trim($this->string('refund_reference')->value());

        return $reference === '' ? null : $reference;
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
