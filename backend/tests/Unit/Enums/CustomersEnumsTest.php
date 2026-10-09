<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;

test('every customers enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([CustomerStatus::class, OtpPurpose::class, AddressStatus::class]);

test('BR-CU05 the customer statuses match the domain model', function (): void {
    expect(array_map(fn (CustomerStatus $status): string => $status->value, CustomerStatus::cases()))
        ->toBe(['pending_verification', 'invited', 'pending_approval', 'active', 'blocked']);
});

test('BR-CU05 only active customers can log in', function (CustomerStatus $status, bool $canLogin): void {
    expect($status->canLogin())->toBe($canLogin);
})->with([
    'pending verification' => [CustomerStatus::PendingVerification, false],
    'invited' => [CustomerStatus::Invited, false],
    'pending approval' => [CustomerStatus::PendingApproval, false],
    'active' => [CustomerStatus::Active, true],
    'blocked' => [CustomerStatus::Blocked, false],
]);

test('BR-CU05 the customer state machine', function (CustomerStatus $from, CustomerStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'verified without approval' => [CustomerStatus::PendingVerification, CustomerStatus::Active, true],
    'verified with approval' => [CustomerStatus::PendingVerification, CustomerStatus::PendingApproval, true],
    'invitation used' => [CustomerStatus::Invited, CustomerStatus::Active, true],
    'invitation used, approval on' => [CustomerStatus::Invited, CustomerStatus::PendingApproval, true],
    'approved' => [CustomerStatus::PendingApproval, CustomerStatus::Active, true],
    'any status can be blocked' => [CustomerStatus::Active, CustomerStatus::Blocked, true],
    'an invited customer can be blocked' => [CustomerStatus::Invited, CustomerStatus::Blocked, true],
    'unblocked back to active' => [CustomerStatus::Blocked, CustomerStatus::Active, true],
    'unblocked back to invited' => [CustomerStatus::Blocked, CustomerStatus::Invited, true],
    'not blocked twice' => [CustomerStatus::Blocked, CustomerStatus::Blocked, false],
    'active never goes back to pending' => [CustomerStatus::Active, CustomerStatus::PendingApproval, false],
    'pending approval never goes back to invited' => [CustomerStatus::PendingApproval, CustomerStatus::Invited, false],
    'pending verification never becomes invited' => [CustomerStatus::PendingVerification, CustomerStatus::Invited, false],
    'invited never becomes pending verification' => [CustomerStatus::Invited, CustomerStatus::PendingVerification, false],
]);

test('BR-CU07 the otp purposes', function (): void {
    expect(array_map(fn (OtpPurpose $purpose): string => $purpose->value, OtpPurpose::cases()))->toBe(['register', 'password_reset']);
});
