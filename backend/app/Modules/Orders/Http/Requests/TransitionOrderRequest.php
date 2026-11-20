<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * POST /api/dashboard/orders/{number}/transition: `{ to, note?, collect_full_amount?, delivered_quantities? }`
 * (08-api-contract.md §9). Cancelling has its own endpoint, so `to` is one of the four forward statuses.
 * `delivered_quantities` (`[{ order_item_id, quantity }]`, a line once) records a partial delivery (BR-O13); it is ignored
 * for other targets, like `collect_full_amount`.
 */
final class TransitionOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in([
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
                OrderStatus::OutForDelivery->value,
                OrderStatus::Delivered->value,
            ])],
            'note' => ['nullable', 'string', 'max:300'],
            'collect_full_amount' => ['sometimes', 'boolean'],
            'delivered_quantities' => ['sometimes', 'nullable', 'array', 'max:'.QuoteOrderRequest::MAX_LINES],
            'delivered_quantities.*' => ['array:order_item_id,quantity'],
            'delivered_quantities.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'delivered_quantities.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function to(): OrderStatus
    {
        return OrderStatus::from($this->string('to')->value());
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    public function collectFullAmount(): bool
    {
        return $this->boolean('collect_full_amount');
    }

    /**
     * BR-O13: order item id => delivered selling units, in the order sent; null when not sent.
     *
     * @return array<int, int>|null
     */
    public function deliveredQuantities(): ?array
    {
        if (! is_array($this->input('delivered_quantities'))) {
            return null;
        }

        /** @var list<array{order_item_id: int|string, quantity: int|string}> $lines */
        $lines = $this->array('delivered_quantities');
        $delivered = [];

        foreach ($lines as $line) {
            $delivered[(int) $line['order_item_id']] = (int) $line['quantity'];
        }

        return $delivered;
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
