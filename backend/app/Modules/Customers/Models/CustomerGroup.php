<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BR-PR12 (V2 Task 11, D89): a wholesale level such as «موزع» or «مكتبة كبيرة», with one % off the base price for its
 * customers. Unique name per tenant; archived instead of deleted (its customers keep the id, the discount stops).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $discount_percent 0.00–90.00
 * @property CustomerGroupStatus $status
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $customers_count
 */
final class CustomerGroup extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerGroupFactory> */
    use HasFactory;

    protected $attributes = [
        'discount_percent' => '0.00',
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected static function newFactory(): CustomerGroupFactory
    {
        return CustomerGroupFactory::new();
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->status === CustomerGroupStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'status' => CustomerGroupStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
