<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Enums\VerticalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VerticalSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A default section of a vertical (D109 a), copied to the vertical's tenants (V3 Task 02).
 *
 * @property int $id
 * @property int $vertical_id
 * @property string $key
 * @property string $name
 * @property string $reference_price_label
 * @property string|null $icon
 * @property VerticalStatus $status
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vertical $vertical
 */
final class VerticalSection extends Model
{
    /** @use HasFactory<VerticalSectionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
        'reference_price_label' => 'سعر القطاعي',
    ];

    protected static function newFactory(): VerticalSectionFactory
    {
        return VerticalSectionFactory::new();
    }

    /**
     * @return BelongsTo<Vertical, $this>
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerticalStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
