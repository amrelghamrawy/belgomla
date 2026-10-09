<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Casts\E164Phone;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-CU03: one of a customer's delivery addresses; at most one active default per customer (partial unique index).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $label
 * @property string $governorate
 * @property string $city
 * @property string $address_line
 * @property string|null $landmark
 * @property string|null $phone E.164
 * @property bool $is_default
 * @property AddressStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 */
final class CustomerAddress extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    protected $attributes = [
        'is_default' => false,
        'status' => 'active',
    ];

    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => E164Phone::class,
            'is_default' => 'boolean',
            'status' => AddressStatus::class,
        ];
    }
}
