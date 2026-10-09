<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array never exposes the body', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create()->fresh());

    expect(array_keys($message->toArray()))->toBe([
        'id', 'tenant_id', 'uuid', 'to_phone', 'purpose', 'channel', 'provider', 'provider_request_id', 'provider_message_id',
        'status', 'error_code', 'fallback_of_id', 'cost', 'created_at', 'updated_at',
    ]);
});

test('casts: enums, a 4-decimal cost and immutable timestamps', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['cost' => 0.05])->fresh());

    expect($message->purpose)->toBe(MessagePurpose::Otp)
        ->and($message->channel)->toBe(Channel::WhatsApp)
        ->and($message->provider)->toBe(Provider::Hypersender)
        ->and($message->status)->toBe(MessageStatus::Queued)
        ->and($message->cost)->toBe('0.0500')
        ->and($message->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('the body is stored encrypted', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['body' => 'رمز التحقق: 654321']));

    $raw = DB::table('outbound_messages')->where('id', $message->id)->value('body');

    expect($raw)->toBeString()->not->toContain('654321')
        ->and($this->inTenant(fn () => $message->fresh()?->body))->toBe('رمز التحقق: 654321');
});

test('an sms fallback points at the whatsapp message it replaces', function (): void {
    [$whatsapp, $sms] = $this->inTenant(function (): array {
        $whatsapp = OutboundMessage::factory()->fellBack()->create();

        return [$whatsapp, OutboundMessage::factory()->sms()->create(['fallback_of_id' => $whatsapp->id])];
    });

    expect($this->inTenant(fn () => $sms->fallbackOf?->is($whatsapp)))->toBeTrue()
        ->and($this->inTenant(fn () => $whatsapp->fallback?->is($sms)))->toBeTrue();
});

test('BR-T03 a message created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => OutboundMessage::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a message without a tenant in context throws', function (): void {
    OutboundMessage::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 message queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => OutboundMessage::factory()->create());
    app(TenantContext::class)->clear();

    expect(OutboundMessage::query()->count())->toBe(0);
});

test('database checks refuse unknown values and a whatsapp fallback', function (array $attributes, string $constraint): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());

    expect(fn () => DB::table('outbound_messages')->where('id', $message->id)->update($attributes))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'unknown purpose' => [['purpose' => 'marketing'], 'outbound_messages_purpose_known'],
    'unknown channel' => [['channel' => 'email'], 'outbound_messages_channel_known'],
    'unknown status' => [['status' => 'read'], 'outbound_messages_status_known'],
]);

test('BR-CU07 only an sms can be a fallback', function (): void {
    expect(fn () => $this->inTenant(function (): void {
        $whatsapp = OutboundMessage::factory()->fellBack()->create();
        OutboundMessage::factory()->whatsapp()->create(['fallback_of_id' => $whatsapp->id]);
    }))->toThrow(QueryException::class, 'outbound_messages_fallback_is_sms');
});

test('BR-CU07 a whatsapp message has at most one sms fallback', function (): void {
    expect(fn () => $this->inTenant(function (): void {
        $whatsapp = OutboundMessage::factory()->fellBack()->create();
        OutboundMessage::factory()->sms()->count(2)->create(['fallback_of_id' => $whatsapp->id]);
    }))->toThrow(QueryException::class, 'fallback_of_id');
});
