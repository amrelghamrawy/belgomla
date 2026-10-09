<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFieldOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value of a `select` custom field. Products store its id (BR-P08).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $definition_id
 * @property string $label
 * @property int $sort_order
 * @property ArchiveStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ProductFieldDefinition $definition
 */
final class ProductFieldOption extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductFieldOptionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected static function newFactory(): ProductFieldOptionFactory
    {
        return ProductFieldOptionFactory::new();
    }

    /**
     * @return BelongsTo<ProductFieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ProductFieldDefinition::class, 'definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => ArchiveStatus::class,
        ];
    }
}
