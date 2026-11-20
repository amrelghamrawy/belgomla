<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VerticalFieldTemplateOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One default option of a `select` field template, copied to tenants by label (D110).
 *
 * @property int $id
 * @property int $template_id
 * @property string $label
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read VerticalFieldTemplate $template
 */
final class VerticalFieldTemplateOption extends Model
{
    /** @use HasFactory<VerticalFieldTemplateOptionFactory> */
    use HasFactory;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected static function newFactory(): VerticalFieldTemplateOptionFactory
    {
        return VerticalFieldTemplateOptionFactory::new();
    }

    /**
     * @return BelongsTo<VerticalFieldTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(VerticalFieldTemplate::class, 'template_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
