<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Resources;

use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The head of a customer statement, sent next to the paginated orders (08-api-contract.md §10): who it is for and
 * the totals over all their non-cancelled orders.
 *
 * @property-read Customer $resource
 */
final class StatementResource extends JsonResource
{
    /**
     * @param  array{orders_count: int, total: string, collected: string, outstanding: string}  $summary
     */
    public function __construct(Customer $resource, private readonly array $summary)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer' => [
                'id' => $this->resource->id,
                'name' => $this->resource->name,
                'phone' => $this->resource->phone,
            ],
            'summary' => $this->summary,
        ];
    }
}
