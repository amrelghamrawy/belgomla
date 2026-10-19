<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Collections\Models\CustomerCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer credit (08-api-contract.md §10, BR-C07): how much, how much is left, the return it came from, and the
 * void details. `created_by` / `voided_by` are tenant user ids (null = system).
 *
 * @property-read CustomerCredit $resource
 */
final class CustomerCreditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credit = $this->resource;

        return [
            'id' => $credit->id,
            'customer_id' => $credit->customer_id,
            'source_return_id' => $credit->source_return_id,
            'amount' => $credit->amount,
            'remaining' => $credit->remaining,
            'note' => $credit->note,
            'created_by' => $credit->created_by,
            'created_at' => $credit->created_at->toIso8601String(),
            'is_voided' => $credit->voided_at !== null,
            'voided_at' => $credit->voided_at?->toIso8601String(),
            'void_reason' => $credit->void_reason,
            'voided_by' => $credit->voided_by,
        ];
    }
}
