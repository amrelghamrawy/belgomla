<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Queries\LastOtpCodeQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('D62 the code comes from the newest otp message to the phone', function (): void {
    $this->inTenant(function (): void {
        OutboundMessage::factory()->create(['body' => 'رمز التحقق: 111111']);
        OutboundMessage::factory()->create(['body' => 'رمز التحقق: 222222']);
        OutboundMessage::factory()->create(['to_phone' => '+201112345678', 'body' => 'رمز التحقق: 333333']);
    });

    $code = $this->inTenant(fn (): string => app(LastOtpCodeQuery::class)->get('+201012345678'));

    expect($code)->toBe('222222');
});

test('D62 invitations and cleared bodies are skipped', function (): void {
    $this->inTenant(function (): void {
        OutboundMessage::factory()->create(['body' => 'رمز التحقق: 111111']);
        OutboundMessage::factory()->create(['purpose' => MessagePurpose::Invitation, 'body' => 'دعوة 222222']);
        OutboundMessage::factory()->delivered()->create();
    });

    $code = $this->inTenant(fn (): string => app(LastOtpCodeQuery::class)->get('+201012345678'));

    expect($code)->toBe('111111');
});

test('D62 a body without a 6-digit code is not found', function (): void {
    $this->inTenant(fn () => OutboundMessage::factory()->create(['body' => 'لا يوجد رمز']));

    expect(fn () => $this->inTenant(fn (): string => app(LastOtpCodeQuery::class)->get('+201012345678')))
        ->toThrow(ModelNotFoundException::class);
});
