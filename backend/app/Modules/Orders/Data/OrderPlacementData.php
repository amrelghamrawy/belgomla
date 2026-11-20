<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Models\Order;

/**
 * What PlaceOrder needs to turn lines into a pending order (BR-PR06, BR-O11): for whom, where to deliver, the lines,
 * who placed it (the customer at checkout, or a dashboard user), the note, and whether a merchant waived the
 * minimum order value for this one order (D84). A pre-order (`kind = preorder`, BR-O14) is placed the same way without
 * stock; a normal order converted from a pre-order (`convertedFrom`, D88) takes that pre-order's delivery snapshot
 * instead of an address. Built by Checkout, PlaceOrderForCustomer, PlacePreorder and ConvertPreorders, not from a
 * request.
 */
final readonly class OrderPlacementData
{
    /**
     * @param  list<CartLineInput>  $lines
     */
    public function __construct(
        public Customer $customer,
        public ?int $addressId,
        public array $lines,
        public ActorType $placedByType,
        public int $placedById,
        public ?string $note = null,
        public bool $skipMov = false,
        public OrderKind $kind = OrderKind::Order,
        public ?Order $convertedFrom = null,
    ) {}

    public function byMerchant(): bool
    {
        return $this->placedByType === ActorType::TenantUser;
    }
}
