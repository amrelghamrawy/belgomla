<?php

declare(strict_types=1);

namespace App\Modules\Collections\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerCreditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-C07: money the store owes a customer and keeps for their next orders instead of paying it back in cash: the
 * over-collection of a return (`source_return_id`). `remaining` falls as it is applied to orders (`credit`
 * collections); voided with a reason only while nothing of it was applied, never deleted. `created_by` /
 * `voided_by` are tenant user ids (null = system).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $source_return_id
 * @property string $amount
 * @property string $remaining
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Customer $customer
 */
final class CustomerCredit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerCreditFactory> */
    use HasFactory;

    protected static function newFactory(): CustomerCreditFactory
    {
        return CustomerCreditFactory::new();
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
            'source_return_id' => 'integer',
            'amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'created_by' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }
}
