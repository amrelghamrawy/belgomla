<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Platform\Enums\VerticalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VerticalFieldTemplateFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A default custom field of a vertical, optionally bound to one of its sections (D110 a, BR-P09).
 *
 * @property int $id
 * @property int $vertical_id
 * @property int|null $vertical_section_id
 * @property string $key
 * @property string $label
 * @property FieldType $type
 * @property bool $is_required
 * @property bool $is_filterable
 * @property int $filter_order
 * @property int $sort_order
 * @property VerticalStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vertical $vertical
 * @property-read VerticalSection|null $section
 * @property-read Collection<int, VerticalFieldTemplateOption> $options
 */
final class VerticalFieldTemplate extends Model
{
    /** @use HasFactory<VerticalFieldTemplateFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'is_required' => false,
        'is_filterable' => false,
        'filter_order' => 0,
        'sort_order' => 0,
    ];

    protected static function newFactory(): VerticalFieldTemplateFactory
    {
        return VerticalFieldTemplateFactory::new();
    }

    /**
     * @return BelongsTo<Vertical, $this>
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /**
     * @return BelongsTo<VerticalSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(VerticalSection::class, 'vertical_section_id');
    }

    /**
     * @return HasMany<VerticalFieldTemplateOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(VerticalFieldTemplateOption::class, 'template_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'filter_order' => 'integer',
            'sort_order' => 'integer',
            'status' => VerticalStatus::class,
        ];
    }
}
