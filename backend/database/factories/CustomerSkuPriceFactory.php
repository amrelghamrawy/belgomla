<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Models\CustomerSkuPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerSkuPrice> */
final class CustomerSkuPriceFactory extends Factory
{
    protected $model = CustomerSkuPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'sku_id' => SkuFactory::new(),
            'unit_price' => '80.00',
            'note' => null,
        ];
    }
}
