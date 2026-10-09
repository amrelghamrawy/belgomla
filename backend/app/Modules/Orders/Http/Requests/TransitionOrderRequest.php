<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * POST /api/dashboard/orders/{number}/transition: `{ to, note?, collect_full_amount? }` (08-api-contract.md §9).
 * Cancelling has its own endpoint, so `to` is one of the four forward statuses.
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

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
