<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Http\Requests\RegisterCustomerRequest;

/** BR-CU04: a storefront self-registration; the phone is already E.164. */
final readonly class RegistrationData
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $password,
    ) {}

    public static function fromRequest(RegisterCustomerRequest $request): self
    {
        return new self($request->name(), $request->phone(), $request->password());
    }
}
