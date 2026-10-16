<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\StoreFieldRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateFieldRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * @template TRequest of FormRequest
 *
 * @param  class-string<TRequest>  $class
 * @param  array<string, mixed>  $payload
 * @param  array<string, UploadedFile>  $files
 * @return TRequest
 */
function validatedRequest(string $class, string $method, array $payload, array $files = []): FormRequest
{
    $request = $class::create('/', $method, $payload, [], $files);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

test('category data from a create request trims the name and carries the image', function (): void {
    $image = UploadedFile::fake()->image('a.png');

    $data = CategoryData::fromRequest(validatedRequest(StoreCategoryRequest::class, 'POST', ['name' => '  أقلام ', 'parent_id' => '7', 'sort_order' => '2', 'icon' => 'pencil'], ['image' => $image]));

    expect($data->name)->toBe('أقلام')
        ->and($data->parentId)->toBe(7)
        ->and($data->sortOrder)->toBe(2)
        ->and($data->icon)->toBe(CategoryIcon::Pencil)
        ->and($data->iconGiven)->toBeTrue()
        ->and($data->image)->toBeInstanceOf(UploadedFile::class);
});

test('category data leaves unsent optional fields null', function (): void {
    $data = CategoryData::fromRequest(validatedRequest(StoreCategoryRequest::class, 'POST', ['name' => 'x']));

    expect($data->parentId)->toBeNull()
        ->and($data->sortOrder)->toBeNull()
        ->and($data->icon)->toBeNull()
        ->and($data->iconGiven)->toBeFalse()
        ->and($data->image)->toBeNull();
});

test('category data from an update request never carries an image and keeps an explicit null parent', function (): void {
    $data = CategoryData::fromRequest(validatedRequest(UpdateCategoryRequest::class, 'PUT', ['name' => 'x', 'parent_id' => null]));

    expect($data->parentId)->toBeNull()
        ->and($data->sortOrder)->toBeNull()
        ->and($data->image)->toBeNull();
});

test('field data from a create request carries key, typed flags and trimmed options', function (): void {
    $data = FieldDefinitionData::fromRequest(validatedRequest(StoreFieldRequest::class, 'POST', [
        'key' => 'grade', 'label' => ' السنة ', 'type' => 'select', 'is_filterable' => '1', 'is_required' => 0,
        'filter_order' => '2', 'options' => [' أولى ', 'ثانية'],
    ]));

    expect($data->key)->toBe('grade')
        ->and($data->label)->toBe('السنة')
        ->and($data->type)->toBe(FieldType::Select)
        ->and($data->isFilterable)->toBeTrue()
        ->and($data->isRequired)->toBeFalse()
        ->and($data->showOnStorefront)->toBeNull()
        ->and($data->filterOrder)->toBe(2)
        ->and($data->kind)->toBeNull()
        ->and($data->kindGiven)->toBeFalse()
        ->and($data->sortOrder)->toBeNull()
        ->and($data->options)->toBe(['أولى', 'ثانية']);
});

test('field data from an update request has no key and no options', function (): void {
    $data = FieldDefinitionData::fromRequest(validatedRequest(UpdateFieldRequest::class, 'PUT', [
        'label' => 'x', 'type' => 'boolean', 'show_on_storefront' => false, 'kind' => 'toys',
    ]));

    expect($data->key)->toBeNull()
        ->and($data->options)->toBe([])
        ->and($data->type)->toBe(FieldType::Boolean)
        ->and($data->showOnStorefront)->toBeFalse()
        ->and($data->kind)->toBe(CategoryKind::Toys)
        ->and($data->kindGiven)->toBeTrue()
        ->and($data->isFilterable)->toBeNull();
});

test('D69 FieldDefinitionData tells a kind sent as null apart from a kind not sent', function (): void {
    $data = FieldDefinitionData::fromRequest(validatedRequest(UpdateFieldRequest::class, 'PUT', ['label' => 'x', 'type' => 'text', 'kind' => null]));

    expect($data->kind)->toBeNull()
        ->and($data->kindGiven)->toBeTrue();
});

test('D73 CategoryData tells an icon sent as null apart from an icon not sent', function (): void {
    $data = CategoryData::fromRequest(validatedRequest(UpdateCategoryRequest::class, 'PUT', ['name' => 'x', 'parent_id' => null, 'icon' => null]));

    expect($data->icon)->toBeNull()
        ->and($data->iconGiven)->toBeTrue();
});
