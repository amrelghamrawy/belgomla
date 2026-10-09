<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\ActivateInvitation;
use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Http\Requests\ActivateInvitationRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Http\Resources\InvitationResource;
use App\Modules\Customers\Queries\InvitationByTokenQuery;

final readonly class InvitationController
{
    /** GET /api/store/auth/invitation/{token} — BR-CU08 */
    public function show(string $token, InvitationByTokenQuery $invitations): InvitationResource
    {
        return new InvitationResource($invitations->live($token));
    }

    /** POST /api/store/auth/invitation/{token} — BR-CU08: set a password, activate, start a session */
    public function store(ActivateInvitationRequest $request, string $token, ActivateInvitation $activate): CustomerResource
    {
        if (! $request->hasSession()) {
            throw LoginRejected::statefulRequestRequired();
        }

        return new CustomerResource($activate->handle($token, $request->password(), $request->session()));
    }
}
