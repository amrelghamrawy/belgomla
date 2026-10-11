<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Observers\FacetCacheObserver;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFieldDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-defined custom product field (BR-P08). `kind` binds it to one section; null = every section (D69).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $label
 * @property FieldType $type
 * @property CategoryKind|null $kind
 * @property bool $is_required
 * @property bool $show_on_storefront
 * @property bool $is_filterable
 * @property int $filter_order
 * @property int $sort_order
 * @property ArchiveStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ProductFieldOption> $options
 */
#[ObservedBy(FacetCacheObserver::class)]
final class ProductFieldDefinition extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductFieldDefinitionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'is_required' => false,
        'show_on_storefront' => true,
        'is_filterable' => false,
        'filter_order' => 0,
        'sort_order' => 0,
    ];

    protected static function newFactory(): ProductFieldDefinitionFactory
    {
        return ProductFieldDefinitionFactory::new();
    }

    /**
     * @return HasMany<ProductFieldOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductFieldOption::class, 'definition_id')->orderBy('sort_order')->orderBy('id');
    }

    /** True when the field applies to products of this section (BR-P08, D69). */
    public function appliesTo(CategoryKind $kind): bool
    {
        return $this->kind === null || $this->kind === $kind;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'kind' => CategoryKind::class,
            'is_required' => 'boolean',
            'show_on_storefront' => 'boolean',
            'is_filterable' => 'boolean',
            'filter_order' => 'integer',
            'sort_order' => 'integer',
            'status' => ArchiveStatus::class,
        ];
    }
}
