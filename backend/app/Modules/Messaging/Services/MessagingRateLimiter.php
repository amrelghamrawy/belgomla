<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiter;

/**
 * BR-CU07: at most 5 messages per phone per hour in a tenant (refused with 429). D79: order status messages count in
 * a bucket of their own (10 per phone per hour), so a busy order day never blocks a verification code. D46: at most 10
 * messages per minute to the provider across all tenants (the job waits, nothing is refused).
 */
final readonly class MessagingRateLimiter
{
    public function __construct(private RateLimiter $limiter, private TenantContext $context) {}

    public function ensurePhoneAllowed(string $phone, MessagePurpose $purpose = MessagePurpose::Otp): void
    {
        $orders = $purpose === MessagePurpose::OrderStatus;
        $key = 'messaging:phone:'.($orders ? 'orders:' : '').$this->context->id().':'.$phone;
        $limit = config()->integer($orders ? 'messaging.limits.order_status_per_phone_per_hour' : 'messaging.limits.per_phone_per_hour');

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            throw MessagingRateLimited::forPhone($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 3600);
    }

    /**
     * Seconds to wait before the provider may be called again, or null when a send may go now (and is counted).
     */
    public function providerDelay(): ?int
    {
        $key = 'messaging:provider';

        if ($this->limiter->tooManyAttempts($key, config()->integer('messaging.limits.provider_per_minute'))) {
            return max(1, $this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 60);

        return null;
    }
}
