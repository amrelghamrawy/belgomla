<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Collections\Enums\LedgerEntryType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a customer statement (08-api-contract.md §10, BR-C08), the same on the dashboard and the storefront: when,
 * what kind, which order (and return), the debit or credit it brings and the balance after it.
 *
 * @property-read object{occurred_at: string, type: string, order_number: int|string|null, return_number: int|string|null, debit: string, credit: string, balance: string} $resource
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

        return [
            'date' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
            'type' => $type->value,
            'type_label' => $type->label(),
            'order_number' => $row->order_number === null ? null : (int) $row->order_number,
            'return_number' => $row->return_number === null ? null : (int) $row->return_number,
            'debit' => Money::add('0.00', $row->debit),
            'credit' => Money::add('0.00', $row->credit),
            'balance' => Money::add('0.00', $row->balance),
        ];
    }
}
