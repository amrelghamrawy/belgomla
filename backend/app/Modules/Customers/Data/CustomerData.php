<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;

/**
 * BR-CU08: a customer the merchant creates or edits. On create the phone is required and the addresses are
 * added; on update the phone is null (it cannot change) and `addresses` null keeps them, a list syncs them (D54).
 * Credit terms (BR-C05, BR-C06) count only when listed in `creditTermsSent`: a new customer takes the store's defaults
 * for the others, an edit keeps them. The group (BR-PR12) counts only when `groupSent`: null then means no group.
 */
final readonly class CustomerData
{
    /**
     * @param  list<AddressData>|null  $addresses
     * @param  list<string>  $creditTermsSent  `credit_limit` and/or `payment_terms_days`
     */
    public function __construct(
        public string $name,
        public ?string $phone,
        public ?array $addresses,
        public ?string $creditLimit = null,
        public ?int $paymentTermsDays = null,
        public array $creditTermsSent = [],
        public ?int $groupId = null,
        public bool $groupSent = false,
    ) {}

    public static function fromRequest(StoreCustomerRequest|UpdateCustomerRequest $request): self
    {
        return new self(
            $request->name(),
            $request instanceof StoreCustomerRequest ? $request->phone() : null,
            $request->addresses(),
            $request->creditLimit(),
            $request->paymentTermsDays(),
            $request->creditTermsSent(),
            $request->groupId(),
            $request->groupSent(),
        );
    }
}
