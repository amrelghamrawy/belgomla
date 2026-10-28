<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

beforeEach(function (): void {
    $this->inTenant(function (): void {
        $publisher = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'publisher', 'label' => 'الشركة', 'filter_order' => 3]);
        $grade = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'grade', 'label' => 'السنة الدراسية', 'filter_order' => 1]);
        ProductFieldDefinition::factory()->select()->create(['key' => 'not_a_facet']);

        $this->first = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى إعدادي', 'sort_order' => 1]);
        $this->second = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'ثانية إعدادي', 'sort_order' => 2]);
        $this->empty = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'ثالثة إعدادي', 'sort_order' => 3]);
        $this->adwaa = ProductFieldOption::factory()->for($publisher, 'definition')->create(['label' => 'الأضواء']);
        $this->moalem = ProductFieldOption::factory()->for($publisher, 'definition')->create(['label' => 'المعلم']);

        Product::factory()->create(['custom_fields' => ['grade' => $this->first->id, 'publisher' => $this->adwaa->id]]);
        Product::factory()->create(['custom_fields' => ['grade' => $this->first->id, 'publisher' => $this->moalem->id]]);
        Product::factory()->create(['custom_fields' => ['grade' => $this->second->id, 'publisher' => $this->adwaa->id]]);
        Product::factory()->inactive()->create(['custom_fields' => ['grade' => $this->second->id, 'publisher' => $this->moalem->id]]);
    });
});

test('D18 facets come in filter order with options and counts of visible products', function (): void {
    $this->tenantJson('GET', '/api/store/filters')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['*' => ['key', 'label', 'type', 'options' => ['*' => ['id', 'label', 'count']]]]])
        ->assertExactJson(['data' => [
            ['key' => 'grade', 'label' => 'السنة الدراسية', 'type' => 'select', 'options' => [
                ['id' => $this->first->id, 'label' => 'أولى إعدادي', 'count' => 2],
                ['id' => $this->second->id, 'label' => 'ثانية إعدادي', 'count' => 1],
            ]],
            ['key' => 'publisher', 'label' => 'الشركة', 'type' => 'select', 'options' => [
                ['id' => $this->adwaa->id, 'label' => 'الأضواء', 'count' => 2],
                ['id' => $this->moalem->id, 'label' => 'المعلم', 'count' => 1],
            ]],
        ]]);
});

test('BR-P08 a facet counts with the other selected facets but ignores its own selection', function (): void {
    $this->tenantJson('GET', "/api/store/filters?f[publisher]={$this->moalem->id}")
        ->assertOk()
        ->assertJsonPath('data.0.key', 'grade')
        ->assertJsonPath('data.0.options', [['id' => $this->first->id, 'label' => 'أولى إعدادي', 'count' => 1]])
        ->assertJsonPath('data.1.key', 'publisher')
        ->assertJsonPath('data.1.options.0.count', 2)
        ->assertJsonPath('data.1.options.1.count', 1);
});

test('the facet counts follow a product change immediately', function (): void {
    $this->tenantJson('GET', '/api/store/filters')->assertJsonPath('data.0.options.1.count', 1);

    $this->inTenant(fn () => Product::factory()->create(['custom_fields' => ['grade' => $this->second->id]]));

    $this->tenantJson('GET', '/api/store/filters')->assertJsonPath('data.0.options.1.count', 2);
});

test('an archived option disappears from the facets', function (): void {
    $this->tenantJson('GET', '/api/store/filters')->assertJsonCount(2, 'data.1.options');

    $this->inTenant(fn () => $this->moalem->update(['status' => 'archived']));

    $this->tenantJson('GET', '/api/store/filters')->assertJsonCount(1, 'data.1.options');
});

test('facets respect the search and the category of the selection', function (): void {
    $this->tenantJson('GET', '/api/store/filters?category=no-such-category')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('BR-T05 facets never count products of another tenant', function (): void {
    $this->inTenant(fn () => Product::factory()->count(5)->create(['custom_fields' => ['grade' => $this->first->id]]), $this->otherTenant());

    $this->tenantJson('GET', '/api/store/filters')->assertJsonPath('data.0.options.0.count', 2);
});

test('BR-P14 the variant options are facets after the fields, keyed by the folded option name', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود'], code: 'PEN');
    $this->variantProduct(colours: ['أزرق'], name: 'قلم رصاص', code: 'PCL');

    $this->tenantJson('GET', '/api/store/filters')
        ->assertOk()
        ->assertJsonPath('data.*.key', ['grade', 'publisher', 'o:اللون'])
        ->assertJsonPath('data.2', ['key' => 'o:اللون', 'label' => 'اللون', 'type' => 'option', 'options' => [
            ['id' => 'ازرق', 'label' => 'أزرق', 'count' => 2],
            ['id' => 'اسود', 'label' => 'أسود', 'count' => 1],
        ]]);
});

test('BR-P14 BR-P08 an option selection narrows the field facets but not its own', function (): void {
    $this->variantProduct(colours: ['أزرق'], code: 'PEN');
    $this->variantProduct(colours: ['أسود'], name: 'قلم رصاص', code: 'PCL');
    $this->inTenant(fn () => Product::query()->where('name', 'قلم جاف')->update(['custom_fields' => ['grade' => $this->second->id]]));

    $this->tenantJson('GET', '/api/store/filters?f[o:اللون]=ازرق')
        ->assertOk()
        ->assertJsonPath('data.*.key', ['grade', 'o:اللون'])
        ->assertJsonPath('data.0.options', [['id' => $this->second->id, 'label' => 'ثانية إعدادي', 'count' => 1]])
        ->assertJsonPath('data.1.options.*.count', [1, 1]);
});
