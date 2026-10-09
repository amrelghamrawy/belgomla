<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\InvitationByTokenQuery;
use App\Modules\Customers\Services\CustomerSession;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU08: an invited customer opens their link and sets a password. The link proves the phone, so it is
 * marked verified; the customer becomes `active`, or `pending_approval` when the tenant requires approval (D2).
 * The link is used up and a session starts. A bad link is `404`. Owns its transaction.
 */
final readonly class ActivateInvitation
{
    public function __construct(
        private InvitationByTokenQuery $invitations,
        private CurrentTenantSettingsQuery $settings,
        private CustomerSession $session,
    ) {}

    public function handle(string $token, string $password, Session $session): Customer
    {
        $invitation = $this->invitations->live($token);
        $customer = $invitation->customer;

        DB::transaction(function () use ($invitation, $customer, $password): void {
            $customer->forceFill([
                'password' => $password,
                'phone_verified_at' => now(),
                'status' => $this->settings->requiresCustomerApproval() ? CustomerStatus::PendingApproval : CustomerStatus::Active,
            ])->save();

            $invitation->forceFill(['used_at' => now()])->save();
        });

        $this->session->start($customer, $session);

        return $customer;
    }
}
