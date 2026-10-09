<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The key is the JSON key inside products.custom_fields, so it is immutable after creation.
 * Options are managed through the options endpoints.
 */
final class UpdateFieldRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['prohibited'],
            'options' => ['prohibited'],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(FieldType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'show_on_storefront' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'filter_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function label(): string
    {
        return mb_trim($this->string('label')->value());
    }

    public function type(): FieldType
    {
        return $this->enum('type', FieldType::class) ?? FieldType::Text;
    }

    public function flag(string $name): ?bool
    {
        return $this->has($name) ? $this->boolean($name) : null;
    }

    public function order(string $name): ?int
    {
        return $this->has($name) ? $this->integer($name) : null;
    }
}
