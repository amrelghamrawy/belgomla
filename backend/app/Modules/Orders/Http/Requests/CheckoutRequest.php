<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/checkout: `{ address_id, customer_note? }` (08-api-contract.md §3). */
final class CheckoutRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'address_id' => ['required', 'integer', 'min:1'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function addressId(): int
    {
        return $this->integer('address_id');
    }

    public function customerNote(): ?string
    {
        $note = mb_trim($this->string('customer_note')->value());

        return $note === '' ? null : $note;
    }
}
