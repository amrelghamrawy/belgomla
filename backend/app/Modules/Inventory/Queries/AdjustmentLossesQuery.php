<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RoundingMode;

/**
 * D100 g: what the stock adjustments of a range cost the shop, at the cost each one moved at (D96 e): a decrease is a
 * loss, an increase (found goods, a surplus) gives some back. The catalog import's adjustments are data entry and do not
 * count (D97 c); opening stock is not an adjustment. Adjustments made before D97 have no reason code and are grouped as
 * `unspecified`. An adjustment without a cost cannot be valued: it is counted in `uncosted`. Amounts are rounded
 * half-up to the piaster after summing (D100 h).
 */
final readonly class AdjustmentLossesQuery
{
    /**
     * @return array{total: string, by_reason: array<string, string>, uncosted: int}
     */
    public function between(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = InventoryMovement::query()
            ->toBase()
            ->where('type', MovementType::Adjustment->value)
            ->whereBetween('created_at', [$start, $end])
            ->where(fn (Builder $query): Builder => $query->whereNull('reason_code')->orWhere('reason_code', '<>', AdjustmentReason::Import->value))
            ->groupByRaw("coalesce(reason_code, 'unspecified')")
            ->selectRaw("coalesce(reason_code, 'unspecified') as reason")
            ->selectRaw('sum(-quantity * coalesce(unit_cost, 0)) as loss')
            ->selectRaw('sum(case when unit_cost is null then 1 else 0 end) as uncosted')
            ->get();

        /** @var array<string, numeric-string> $losses */
        $losses = [];
        $total = '0';
        $uncosted = 0;

        foreach ($rows as $row) {
            /** @var object{reason: string, loss: string, uncosted: int|string} $row */
            $loss = $this->numeric($row->loss);
            $losses[$row->reason] = $loss;
            $total = bcadd($total, $loss, 6);
            $uncosted += (int) $row->uncosted;
        }

        $byReason = [];

        foreach ([...array_map(fn (AdjustmentReason $reason): string => $reason->value, AdjustmentReason::cases()), 'unspecified'] as $key) {
            if (isset($losses[$key])) {
                $byReason[$key] = $this->money($losses[$key]);
            }
        }

        return ['total' => $this->money($total), 'by_reason' => $byReason, 'uncosted' => $uncosted];
    }

    /**
     * @param  numeric-string  $value
     */
    private function money(string $value): string
    {
        return Money::add('0.00', bcround($value, 2, RoundingMode::HalfAwayFromZero));
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $value): string
    {
        return is_numeric($value) ? $value : throw new InvalidArgumentException("\"{$value}\" is not a number.");
    }
}
