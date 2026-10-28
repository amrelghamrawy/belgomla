<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\OrderQuote;
use App\Modules\Pricing\Data\TierData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/dashboard/orders/quote (BR-O11): every line (a SKU unit, D91) priced as checkout would price it now, with its
 * unit's MOQ, the SKU's stock in that unit, where its price came from (`price_source`, BR-PR14), its tiers (the hint next to the quantity) and its `problem` (the checkout_failed entry without
 * `sku_id` and `sku_unit_id`, or null); the items total; the store's minimum order value (BR-MOV02); and `credit` when the order would
 * pass the customer's credit limit (BR-C05), else null. Nothing was saved.
 *
 * @property-read OrderQuote $resource
 */
final class OrderQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource->report;

        return [
            'lines' => array_map($this->line(...), $report->lines),
            'items_total' => $report->total,
            'mov' => ['enabled' => $report->movAmount !== null, 'amount' => $report->movAmount, 'satisfied' => $report->mov === null],
            'credit' => $report->credit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CheckoutLine $line): array
    {
        $sku = $line->sku;
        $unit = $line->unit;
        $problem = $this->resource->report->problemFor($unit->id)?->toArray();
        unset($problem['sku_id'], $problem['sku_unit_id']);

        return [
            'sku_id' => $sku->id,
            'sku_unit_id' => $unit->id,
            'product_name' => $sku->product->name,
            'sku_code' => $sku->code,
            'sku_name' => $sku->name ?? $sku->product->name,
            'unit' => $unit->unit->name,
            'conversion_factor' => $unit->conversion_factor,
            'quantity' => $line->quantity,
            'moq' => $unit->moq,
            'available' => intdiv(max(0, $sku->inventory->available ?? 0), $unit->conversion_factor),
            'cover_price' => $line->price->coverPrice,
            'unit_price' => $line->price->unitPrice,
            'discount_percent' => $line->price->discountPercent,
            'tier_min_quantity' => $line->price->tierMinQuantity,
            'price_source' => $line->price->source->value,
            'line_total' => $line->price->lineTotal,
            'tiers' => array_map(fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'unit_price' => $tier->unitPrice], $this->resource->tiers[$unit->id] ?? []),
            'problem' => $problem === [] ? null : $problem,
        ];
    }
}
