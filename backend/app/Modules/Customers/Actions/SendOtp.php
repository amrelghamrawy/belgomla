<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpSendLimited;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Customers\Services\OtpCodes;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU07: sends a new code to a phone. At most 5 per phone per hour (counted in Redis, per tenant), and
 * "send by SMS instead" only 60 seconds after the previous code. A new code replaces the earlier live ones.
 *
 * Only a phone whose customer can use the purpose gets a code — `pending_verification` for register,
 * `active`/`pending_approval` for a password reset — but the answer is the same either way, so the endpoint
 * does not reveal which numbers have accounts (D52). Opens a transaction that nests into the caller's.
 */
final readonly class SendOtp
{
    public function __construct(
        private OtpCodes $codes,
        private Messenger $messenger,
        private RateLimiter $limiter,
        private TenantContext $context,
    ) {}

    public function handle(string $phone, OtpPurpose $purpose, Channel $channel = Channel::WhatsApp): Channel
    {
        $key = 'customer-otp:'.$this->context->id().':'.$phone;

        if ($this->limiter->tooManyAttempts($key, config()->integer('customers.otp.sends_per_hour'))) {
            throw OtpSendLimited::hourlyLimit($this->limiter->availableIn($key));
        }

        if ($channel === Channel::Sms) {
            $this->ensureSmsAllowed($phone, $purpose);
        }

        if (! $this->eligible($phone, $purpose)) {
            return $channel;
        }

        DB::transaction(function () use ($phone, $purpose, $channel): void {
            CustomerOtp::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = $this->codes->generate();

            CustomerOtp::query()->create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => $this->codes->hash($code),
                'channel' => $channel->value,
                'expires_at' => now()->addMinutes(config()->integer('customers.otp.ttl_minutes')),
            ]);

            $this->messenger->send(new MessagePayload($phone, MessagePurpose::Otp, $this->text($code), $channel));
        });

        $this->limiter->hit($key, 3600);

        return $channel;
    }

    private function ensureSmsAllowed(string $phone, OtpPurpose $purpose): void
    {
        $last = CustomerOtp::query()->where('phone', $phone)->where('purpose', $purpose)->latest('id')->first();

        if (! $last instanceof CustomerOtp) {
            return;
        }

        $allowedAt = $last->created_at->addSeconds(config()->integer('customers.otp.sms_after_seconds'));

        if ($allowedAt->isFuture()) {
            throw OtpSendLimited::smsTooSoon((int) ceil(now()->diffInSeconds($allowedAt)));
        }
    }

    private function eligible(string $phone, OtpPurpose $purpose): bool
    {
        $statuses = match ($purpose) {
            OtpPurpose::Register => [CustomerStatus::PendingVerification],
            OtpPurpose::PasswordReset => [CustomerStatus::Active, CustomerStatus::PendingApproval],
        };

        return Customer::query()->where('phone', $phone)->whereIn('status', $statuses)->exists();
    }

    private function text(string $code): string
    {
        $minutes = config()->integer('customers.otp.ttl_minutes');

        return "رمز التحقق في {$this->context->get()->name}: {$code}\nصالح لمدة {$minutes} دقائق. لا تشاركه مع أحد.";
    }
}
