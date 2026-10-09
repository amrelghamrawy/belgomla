<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models\Scopes;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-INV07, D10: pending orders placed more than `stale_pending_hours` ago, still holding their reservation:
 * `Order::query()->tap(new Stale($hours))`.
 */
final readonly class Stale
{
    public function __construct(private int $hours) {}

    public static function threshold(int $hours): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours($hours);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending)->where('placed_at', '<', self::threshold($this->hours));
    }
}
