<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A SKU with its default unit (D91). The default unit's columns may be given as if they were the SKU's, the way a V1
 * SKU carried them (`selling_unit_id`, `conversion_factor`, `cover_price`, `base_price`, `base_price_percent`, `moq`):
 * they are taken off the SKU when it is made and become its default unit once it is created (`defaultUnit`, loaded).
 *
 * @extends Factory<Sku>
 */
final class SkuFactory extends Factory
{
    /** Default unit columns given on the SKU => the sku_units column. */
    private const array UNIT_COLUMNS = [
        'selling_unit_id' => 'unit_id',
        'conversion_factor' => 'conversion_factor',
        'cover_price' => 'cover_price',
        'base_price' => 'base_price',
        'base_price_percent' => 'base_price_percent',
        'moq' => 'moq',
    ];

    protected $model = Sku::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => ProductFactory::new(),
            'code' => 'SKU-'.fake()->unique()->numberBetween(1, 9_999_999),
            'name' => null,
            'base_unit_id' => UnitFactory::new(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Sku $sku): void {
            $unit = ['conversion_factor' => 1, 'cover_price' => null, 'base_price' => '100.00', 'base_price_percent' => null, 'moq' => 1];

            foreach (self::UNIT_COLUMNS as $given => $column) {
                if (array_key_exists($given, $sku->getAttributes())) {
                    $unit[$column] = $sku->getAttributes()[$given];
                    unset($sku->{$given});
                }
            }

            $sku->setRelation('defaultUnit', new SkuUnit([...$unit, 'is_default' => true]));
        })->afterCreating(function (Sku $sku): void {
            $unit = $sku->defaultUnit;

            if ($unit instanceof SkuUnit && ! $unit->exists) {
                $unit->forceFill(['sku_id' => $sku->id, 'unit_id' => $unit->unit_id ?? UnitFactory::new()->createOne()->id])->save();
            }
        });
    }

    public function withCoverPrice(string $coverPrice = '120.00', ?string $percent = null): static
    {
        return $this->state(['cover_price' => $coverPrice, 'base_price_percent' => $percent]);
    }

    /** Stock in base units (BR-INV02); without it the SKU has no inventory row, i.e. no stock. */
    /** `avgCost` is the average cost per base unit (BR-INV09), null = not known. */
    public function withStock(int $onHand, int $reserved = 0, ?string $avgCost = null): static
    {
        return $this->has(SkuInventoryFactory::new()->stock($onHand, $reserved, $avgCost), 'inventory');
    }

    /** The id of the SKU's default unit: what a row made for a SKU (a tier, a cart line, an order line) belongs to. */
    public static function defaultUnitIdOf(mixed $skuId): int
    {
        $id = SkuUnit::query()->where('sku_id', is_numeric($skuId) ? (int) $skuId : 0)->where('is_default', true)->value('id');

        return is_numeric($id) ? (int) $id : 0;
    }
}
