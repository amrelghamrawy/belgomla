<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/orders/{number}/collections: `{ amount, collected_at?, note? }` (08-api-contract.md §10,
 * BR-C02). `collected_at` defaults to now and cannot be in the future.
 */
final class RecordCollectionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'collected_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function amount(): string
    {
        return Money::normalize($this->string('amount')->value());
    }

    public function collectedAt(): ?CarbonImmutable
    {
        return $this->filled('collected_at') ? CarbonImmutable::parse($this->string('collected_at')->value())->utc() : null;
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
