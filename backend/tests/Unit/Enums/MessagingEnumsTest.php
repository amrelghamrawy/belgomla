<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;

test('every messaging enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([MessagePurpose::class, Channel::class, MessageStatus::class, Provider::class]);

test('the enum values match the domain model', function (string $enum, array $values): void {
    expect(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))->toBe($values);
})->with([
    'BR-N01 purposes' => [MessagePurpose::class, ['otp', 'invitation', 'system_alert']],
    'BR-CU07 channels' => [Channel::class, ['whatsapp', 'sms']],
    'statuses' => [MessageStatus::class, ['queued', 'sent', 'delivered', 'failed', 'fell_back']],
    'D11 providers' => [Provider::class, ['hypersender', 'meta_cloud', 'log']],
]);

test('delivered, failed and fell back are final; queued and sent are not', function (): void {
    expect(array_map(fn (MessageStatus $status): bool => $status->isFinal(), MessageStatus::cases()))
        ->toBe([false, false, true, true, true]);
});
