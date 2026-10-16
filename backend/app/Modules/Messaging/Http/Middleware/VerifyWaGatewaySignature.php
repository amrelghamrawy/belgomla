<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * wa-gateway signs every webhook (D70, tasks/12 lesson 5): `X-Gateway-Signature` is the hex HMAC-SHA256 of
 * `{X-Gateway-Timestamp}.{raw body}` under `messaging.wa_gateway.webhook_secret`. A missing, wrong or
 * unconfigured secret, or a timestamp more than 5 minutes away from now, is `401 unauthenticated`.
 */
final readonly class VerifyWaGatewaySignature
{
    private const int MAX_SKEW_SECONDS = 300;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config()->string('messaging.wa_gateway.webhook_secret');
        $timestamp = (string) $request->header('X-Gateway-Timestamp', '');
        $signature = (string) $request->header('X-Gateway-Signature', '');

        $fresh = ctype_digit($timestamp) && abs(now()->getTimestamp() - (int) $timestamp) <= self::MAX_SKEW_SECONDS;
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($secret === '' || ! $fresh || ! hash_equals($expected, $signature)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
