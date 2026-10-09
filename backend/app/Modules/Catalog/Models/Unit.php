<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-defined selling/base unit such as كرتونة or قطعة (BR-U01).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ArchiveStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Unit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArchiveStatus::class,
        ];
    }
}
