<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A customer. On the storefront (`GET /me`) the customer sees their own profile and status (BR-CU05). The
 * dashboard (08-api-contract.md §8) adds `outstanding_amount` (OutstandingBalances), who created and approved
 * them, the block details, and on the detail page the addresses and `recent_orders` (RecentOrders).
 *
 * @property-read Customer $resource
 */
final class CustomerResource extends JsonResource
{
    /**
     * @param  list<array{id: int, number: int, status: string, total: string, placed_at: string}>|null  $recentOrders
     */
    public function __construct(
        Customer $resource,
        private readonly ?string $outstandingAmount = null,
        private readonly ?array $recentOrders = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @param  list<array{id: int, number: int, status: string, total: string, placed_at: string}>|null  $recentOrders
     */
    public static function forDashboard(Customer $customer, string $outstandingAmount, ?array $recentOrders = null): self
    {
        return new self($customer, $outstandingAmount, $recentOrders);
    }

    /**
     * @param  LengthAwarePaginator<int, Customer>  $customers
     * @param  array<int, string>  $outstanding  customer id => amount; missing means "0.00"
     */
    public static function dashboardPage(LengthAwarePaginator $customers, array $outstanding): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection(
            $customers->through(fn (Customer $customer): self => self::forDashboard($customer, $outstanding[$customer->id] ?? '0.00')),
            self::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->resource;
        $dashboard = $this->outstandingAmount !== null;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'status' => $customer->status->value,
            'status_label' => $customer->status->label(),
            'phone_verified_at' => $customer->phone_verified_at?->toIso8601String(),
            'created_at' => $customer->created_at->toIso8601String(),
            'outstanding_amount' => $this->when($dashboard, $this->outstandingAmount),
            'created_by' => $this->when($dashboard, $customer->created_by),
            'approved_by' => $this->when($dashboard, $customer->approved_by),
            'blocked_at' => $this->when($dashboard, $customer->blocked_at?->toIso8601String()),
            'blocked_reason' => $this->when($dashboard, $customer->blocked_reason),
            'addresses' => $this->when($customer->relationLoaded('addresses'), fn (): AnonymousResourceCollection => AddressResource::collection($customer->addresses)),
            'recent_orders' => $this->when($this->recentOrders !== null, $this->recentOrders),
        ];
    }
}
