<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Date;

/**
 * BR-PR15 (V2 Task 12, D90): a time-boxed offer of one % off the base price of the SKUs it covers (`skus`, materialised
 * in `promotion_skus` from the product `filter`, or an explicit list when `filter` is null). Live while its status is
 * scheduled or active and `starts_at ≤ now < ends_at`; never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $discount_percent more than 0, below 90
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property array<string, mixed>|null $filter the dashboard product filter {category, f, q, status}
 * @property PromotionStatus $status
 * @property int|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $skus_count
 */
final class Promotion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }

    /**
     * @return BelongsToMany<Sku, $this>
     */
    public function skus(): BelongsToMany
    {
        return $this->belongsToMany(Sku::class, 'promotion_skus');
    }

    /**
     * D90 c: the status as of now. A scheduled or active promotion is judged by its times, so the dashboard never shows
     * a stale status between two ticks.
     */
    public function currentStatus(?CarbonImmutable $at = null): PromotionStatus
    {
        if (! $this->status->mayBeLive()) {
            return $this->status;
        }

        $at ??= Date::now()->toImmutable();

        return match (true) {
            $at->lt($this->starts_at) => PromotionStatus::Scheduled,
            $at->lt($this->ends_at) => PromotionStatus::Active,
            default => PromotionStatus::Ended,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'filter' => 'array',
            'status' => PromotionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }
}
