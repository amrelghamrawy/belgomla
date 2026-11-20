<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Models\VerticalFieldTemplateOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerticalFieldTemplateOption> */
final class VerticalFieldTemplateOptionFactory extends Factory
{
    protected $model = VerticalFieldTemplateOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => VerticalFieldTemplateFactory::new()->select(),
            'label' => 'خيار '.fake()->unique()->numberBetween(1, 9_999_999),
            'sort_order' => 0,
        ];
    }
}
