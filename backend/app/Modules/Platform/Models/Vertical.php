<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Enums\VerticalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store vertical (نشاط المتجر, D108): platform data, not tenant-owned. Every tenant belongs to exactly one.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property VerticalStatus $status
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, VerticalSection> $sections
 * @property-read Collection<int, VerticalFieldTemplate> $fieldTemplates
 */
final class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected static function newFactory(): VerticalFactory
    {
        return VerticalFactory::new();
    }

    /**
     * @return HasMany<VerticalSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(VerticalSection::class);
    }

    /**
     * @return HasMany<VerticalFieldTemplate, $this>
     */
    public function fieldTemplates(): HasMany
    {
        return $this->hasMany(VerticalFieldTemplate::class);
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
