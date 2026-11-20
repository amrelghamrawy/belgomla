<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/orders/{number}/apply-credit: `{ credit_id, amount }` (08-api-contract.md §10, BR-C07). */
final class ApplyCreditRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'credit_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
        ];
    }

    public function creditId(): int
    {
        return $this->integer('credit_id');
    }

    public function amount(): string
    {
        return Money::normalize($this->string('amount')->value());
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
