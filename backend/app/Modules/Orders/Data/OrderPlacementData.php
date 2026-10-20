<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Customers\Models\Customer;

/**
 * What PlaceOrder needs to turn lines into a pending order (BR-PR06, BR-O11): for whom, where to deliver, the lines,
 * who placed it (the customer at checkout, or a dashboard user), the note, and whether a merchant waived the
 * minimum order value for this one order (D84). Built by Checkout and PlaceOrderForCustomer, not from a request.
 */
final readonly class OrderPlacementData
{
    /**
     * @param  list<CartLineInput>  $lines
     */
    public function __construct(
        public Customer $customer,
        public int $addressId,
        public array $lines,
        public ActorType $placedByType,
        public int $placedById,
        public ?string $note = null,
        public bool $skipMov = false,
    ) {}

    public function byMerchant(): bool
    {
        return $this->placedByType === ActorType::TenantUser;
    }
}
