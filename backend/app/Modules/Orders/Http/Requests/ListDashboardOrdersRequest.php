<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Http\PaginatedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

/**
 * GET /api/dashboard/orders: `?status=&collection_status=&customer_id=&from=&to=&q=&stale=1&sort=` (08-api-contract.md §9).
 * `from`/`to` are calendar days of `placed_at`, both inclusive.
 */
final class ListDashboardOrdersRequest extends PaginatedRequest
{
    /** Sortable columns; the default is newest first. */
    public const array SORTS = ['placed_at', 'number', 'order_total'];

    /**
     * @return array<string, list<string|Enum|In>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'string', new Enum(OrderStatus::class)],
            'collection_status' => ['sometimes', 'string', new Enum(CollectionStatus::class)],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'stale' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', [...self::SORTS, ...array_map(fn (string $sort): string => '-'.$sort, self::SORTS)])],
        ];
    }

    public function filters(): OrderFiltersData
    {
        $q = mb_trim($this->string('q')->value());

        return new OrderFiltersData(
            status: $this->enum('status', OrderStatus::class),
            collectionStatus: $this->enum('collection_status', CollectionStatus::class),
            customerId: $this->has('customer_id') ? $this->integer('customer_id') : null,
            from: $this->day('from')?->startOfDay(),
            to: $this->day('to')?->endOfDay(),
            search: $q === '' ? null : $q,
            stale: $this->boolean('stale'),
            sort: $this->sort() ?? ['placed_at', 'desc'],
        );
    }

    private function day(string $key): ?CarbonImmutable
    {
        return $this->filled($key) ? CarbonImmutable::createFromFormat('Y-m-d', $this->string($key)->value(), 'UTC') ?: null : null;
    }
}
