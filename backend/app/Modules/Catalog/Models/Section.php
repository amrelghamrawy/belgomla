<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of one store's catalog (BR-P16, D109): كتب خارجية, أدوات مكتبية, ألعاب, plus the fallback «عام». Copied
 * from the store's vertical at creation (`vertical_section_id`) or made by the store. Root categories and custom
 * fields point to it. `key` is its machine name (`books`, `general`); until V3 Task 03 it is also the `kind` the API
 * still sends.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $vertical_section_id
 * @property string $key
 * @property string $name
 * @property string $reference_price_label
 * @property CategoryIcon|null $icon
 * @property bool $is_fallback
 * @property ArchiveStatus $status
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, Category> $categories
 */
final class Section extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
        'is_fallback' => false,
        'reference_price_label' => 'سعر القطاعي',
    ];

    protected static function newFactory(): SectionFactory
    {
        return SectionFactory::new();
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'icon' => CategoryIcon::class,
            'is_fallback' => 'boolean',
            'status' => ArchiveStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
