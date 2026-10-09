<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hypersender webhooks carry no signature; each instance sends a fixed `Authorization` header that is set to
 * `messaging.webhook_secret` in the Hypersender dashboard (Tech §13). A missing, wrong or unconfigured
 * secret is `401 unauthenticated`. `Bearer <secret>` is accepted too.
 */
final readonly class VerifyHypersenderWebhook
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config()->string('messaging.webhook_secret');
        $given = (string) $request->header('Authorization', '');

        if (str_starts_with($given, 'Bearer ')) {
            $given = mb_substr($given, 7);
        }

        if ($secret === '' || ! hash_equals($secret, $given)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
