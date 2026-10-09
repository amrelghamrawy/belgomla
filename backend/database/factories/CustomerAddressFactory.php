<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
final class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'label' => 'المحل',
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => '12 شارع عباس العقاد',
            'landmark' => null,
            'phone' => null,
            'is_default' => false,
            'status' => AddressStatus::Active,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function archived(): static
    {
        return $this->state(['status' => AddressStatus::Archived, 'is_default' => false]);
    }
}
