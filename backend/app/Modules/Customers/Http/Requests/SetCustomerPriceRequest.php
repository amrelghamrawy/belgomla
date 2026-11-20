<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/customers/{id}/prices/{sku}: `{ unit_price, note? }` (BR-PR13, V2 Task 11, D89): the unit price in the
 * SKU's selling unit, above 0; it may be above the normal price.
 */
final class SetCustomerPriceRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    public function unitPrice(): string
    {
        return Money::normalize($this->string('unit_price')->value());
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }
}
