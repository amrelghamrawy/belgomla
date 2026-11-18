<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Enums\LedgerEntryType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a customer statement (08-api-contract.md §10, BR-C08), the same on the dashboard and the storefront: when,
 * what kind, which order (and return), the debit or credit it brings and the balance after it; a payment or refund also
 * says how it moved and its reference (BR-C10), null on the other rows.
 *
 * @property-read object{occurred_at: string, type: string, order_number: int|string|null, return_number: int|string|null, debit: string, credit: string, balance: string, method: string|null, reference: string|null} $resource
 */
final class LedgerRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $type = LedgerEntryType::from($row->type);
        $method = $row->method === null ? null : CollectionMethod::from($row->method);

        return [
            'date' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
            'type' => $type->value,
            'type_label' => $type->label(),
            'order_number' => $row->order_number === null ? null : (int) $row->order_number,
            'return_number' => $row->return_number === null ? null : (int) $row->return_number,
            'debit' => Money::add('0.00', $row->debit),
            'credit' => Money::add('0.00', $row->credit),
            'balance' => Money::add('0.00', $row->balance),
            'method' => $method?->value,
            'method_label' => $method?->label(),
            'reference' => $row->reference,
        ];
    }
}
