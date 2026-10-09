<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\TenantOrderCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-O09: the next order number of a tenant, one row per tenant, locked and incremented inside checkout
 * (OrderNumbers).
 *
 * @property int $tenant_id
 * @property int $next_number
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TenantOrderCounter extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantOrderCounterFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $attributes = [
        'next_number' => 1,
    ];

    protected static function newFactory(): TenantOrderCounterFactory
    {
        return TenantOrderCounterFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }
}
