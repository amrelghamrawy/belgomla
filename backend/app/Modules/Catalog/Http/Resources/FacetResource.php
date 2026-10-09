<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One storefront facet (08-api-contract.md §1 GET /filters). Select options carry the option id;
 * boolean options carry "true" / "false", the value to send back as `f[key]`.
 *
 * @property-read array{key: string, label: string, type: string, options: list<array{id: int|string, label: string, count: int}>} $resource
 */
final class FacetResource extends JsonResource
{
    /**
     * @return array{key: string, label: string, type: string, options: list<array{id: int|string, label: string, count: int}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key'],
            'label' => $this->resource['label'],
            'type' => $this->resource['type'],
            'options' => $this->resource['options'],
        ];
    }
}
