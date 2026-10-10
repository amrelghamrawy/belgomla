<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    $this->me = $this->actingAsOwner();
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678', 'status' => CustomerStatus::Invited, 'password' => null]);
});

test('BR-CU08 inviting sends a whatsapp message with a 7-day link, audited', function (): void {
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")
        ->assertOk()
        ->assertExactJson(['data' => ['sent_via' => 'whatsapp', 'expires_at' => now()->addDays(7)->toIso8601String(), 'sent_count' => 1]]);

    $message = $this->inTenant(fn () => OutboundMessage::query()->sole());
    expect($message->purpose)->toBe(MessagePurpose::Invitation)
        ->and($message->to_phone)->toBe('+201012345678')
        ->and(lastMessageText())->toContain('https://alnoor.gomal.test/account/invite/'.lastInvitationToken())
        ->and($this->inTenant(fn () => AuditLog::query()->sole()->only(['action', 'entity_id', 'actor_id', 'new_values'])))
        ->toBe(['action' => 'customer.invited', 'entity_id' => $this->customer->id, 'actor_id' => $this->me->id, 'new_values' => ['sent_count' => 1]]);
    Queue::assertPushed(SendMessage::class);
});

test('BR-CU08 re-sending counts the sends, restarts the 7 days and kills the previous link', function (): void {
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();
    $firstToken = lastInvitationToken();
    $this->travel(3)->days();

    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")
        ->assertOk()
        ->assertJsonPath('data.sent_count', 2)
        ->assertJsonPath('data.expires_at', now()->addDays(7)->toIso8601String());

    expect($this->inTenant(fn () => CustomerInvitation::query()->count()))->toBe(1);
    $this->tenantJson('GET', "/api/store/auth/invitation/{$firstToken}")->assertNotFound();
    $this->tenantJson('GET', '/api/store/auth/invitation/'.lastInvitationToken())->assertOk();
});

test('BR-CU08 only an invited customer can be invited', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition');

    $this->assertDatabaseCount('outbound_messages', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'active' => [CustomerStatus::Active],
    'pending verification' => [CustomerStatus::PendingVerification],
    'pending approval' => [CustomerStatus::PendingApproval],
    'blocked' => [CustomerStatus::Blocked],
]);

test('BR-CU07 the messaging per-phone limit still applies to invitations', function (): void {
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();
    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")->assertOk();

    $this->tenantJson('POST', "/api/dashboard/customers/{$this->customer->id}/invite")
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');

    expect($this->inTenant(fn () => CustomerInvitation::query()->sole()->sent_count))->toBe(5);
});
