<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFieldRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(FieldType::class)],
            'kind' => ['sometimes', 'nullable', Rule::enum(CategoryKind::class)],
            'is_required' => ['sometimes', 'boolean'],
            'show_on_storefront' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'filter_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'options' => ['sometimes', 'array', 'max:500'],
            'options.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }

    public function key(): string
    {
        return $this->string('key')->value();
    }

    public function label(): string
    {
        return mb_trim($this->string('label')->value());
    }

    public function type(): FieldType
    {
        return $this->enum('type', FieldType::class) ?? FieldType::Text;
    }

    /** Null = every section (D69). */
    public function kind(): ?CategoryKind
    {
        return $this->filled('kind') ? $this->enum('kind', CategoryKind::class) : null;
    }

    public function flag(string $name): ?bool
    {
        return $this->has($name) ? $this->boolean($name) : null;
    }

    public function order(string $name): ?int
    {
        return $this->has($name) ? $this->integer($name) : null;
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        $options = $this->input('options', []);

        return is_array($options) ? array_values(array_map(fn (mixed $label): string => is_string($label) ? mb_trim($label) : '', $options)) : [];
    }
}
