<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\InviteCustomer;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Services\InvitationTokens;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->invited()->create(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
});

test('BR-CU08 the first invitation stores the token hash for 7 days and sends the link', function (): void {
    $invitation = app(InviteCustomer::class)->handle($this->customer);

    $token = lastInvitationToken();
    expect($invitation->only(['customer_id', 'token_hash', 'sent_count', 'used_at']))->toBe(['customer_id' => $this->customer->id, 'token_hash' => app(InvitationTokens::class)->hash($token), 'sent_count' => 1, 'used_at' => null])
        ->and($invitation->expires_at->toIso8601String())->toBe(now()->addDays(7)->toIso8601String())
        ->and(OutboundMessage::query()->sole()->purpose)->toBe(MessagePurpose::Invitation)
        ->and(lastMessageText())->toBe("أهلًا مكتبة الأمل، دعاك مكتبة النور للطلب من متجره.\nاضغط الرابط لتحديد كلمة المرور وتفعيل حسابك (صالح 7 أيام):\nhttps://alnoor.gomal.test/invite/{$token}")
        ->and(AuditLog::query()->sole()->only(['action', 'new_values']))->toBe(['action' => 'customer.invited', 'new_values' => ['sent_count' => 1]]);
});

test('BR-CU08 a re-send rotates the token and counts', function (): void {
    $first = app(InviteCustomer::class)->handle($this->customer)->token_hash;

    $again = app(InviteCustomer::class)->handle($this->customer);

    expect($again->token_hash)->not->toBe($first)
        ->and($again->sent_count)->toBe(2)
        ->and(CustomerInvitation::query()->count())->toBe(1);
});

test('only an invited customer can be invited', function (): void {
    app(InviteCustomer::class)->handle(Customer::factory()->create());
})->throws(InvalidCustomerTransition::class);

test('when the message cannot be sent the invitation is not written', function (): void {
    foreach (range(1, 5) as $sent) {
        RateLimiter::hit('messaging:phone:'.$this->tenant->id.':+201012345678', 3600);
    }

    expect(fn () => app(InviteCustomer::class)->handle($this->customer))->toThrow(MessagingRateLimited::class);

    expect(CustomerInvitation::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
