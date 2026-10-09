<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Http\Requests\StoreFieldRequest;
use App\Modules\Catalog\Http\Requests\UpdateFieldRequest;

/**
 * A custom field definition (BR-P08). Nullable flags/orders mean "not sent": defaults on create,
 * keep the current value on update. `key` and `options` are create-only.
 */
final readonly class FieldDefinitionData
{
    /**
     * @param  list<string>  $options  initial select option labels, in order
     */
    public function __construct(
        public string $label,
        public FieldType $type,
        public ?string $key = null,
        public ?bool $isRequired = null,
        public ?bool $showOnStorefront = null,
        public ?bool $isFilterable = null,
        public ?int $filterOrder = null,
        public ?int $sortOrder = null,
        public array $options = [],
    ) {}

    public static function fromRequest(StoreFieldRequest|UpdateFieldRequest $request): self
    {
        $creating = $request instanceof StoreFieldRequest;

        return new self(
            label: $request->label(),
            type: $request->type(),
            key: $creating ? $request->key() : null,
            isRequired: $request->flag('is_required'),
            showOnStorefront: $request->flag('show_on_storefront'),
            isFilterable: $request->flag('is_filterable'),
            filterOrder: $request->order('filter_order'),
            sortOrder: $request->order('sort_order'),
            options: $creating ? $request->options() : [],
        );
    }
}
