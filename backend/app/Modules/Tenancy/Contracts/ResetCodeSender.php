<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

/**
 * D112, BR-T10: delivers a dashboard password reset code to a user's phone. Tenancy cannot depend on Messaging, so
 * Messaging fills this (`Messaging\Services\ResetCodeMessages`, bound in AppServiceProvider). Called inside the tenant,
 * inside the transaction that stores the code.
 */
interface ResetCodeSender
{
    /**
     * @param  string  $phone  E.164
     * @param  string  $text  the whole message, code included
     */
    public function send(string $phone, string $text): void;
}
