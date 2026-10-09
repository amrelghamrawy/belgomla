<?php

declare(strict_types=1);

use App\Modules\Collections\Exceptions\AlreadyVoided;
use App\Modules\Collections\Exceptions\CannotCollect;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Support\ApiException;
use Carbon\CarbonImmutable;

test('every collections exception fixes its status, code, arabic message and details', function (ApiException $e, int $status, string $code, string $message, ?array $details): void {
    expect([$e->status, $e->errorCode, $e->getMessage(), $e->details])->toBe([$status, $code, $message, $details]);
})->with([
    'exceeds balance' => [ExceedsBalance::remaining('300.00'), 422, 'exceeds_balance', 'المبلغ أكبر من المتبقي على الطلب (300.00)', ['remaining' => '300.00']],
    'cancelled order' => [CannotCollect::cancelled(), 409, 'order_cancelled', 'لا يمكن تسجيل تحصيل على طلب ملغي', null],
    'already voided' => [AlreadyVoided::at(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC')), 409, 'already_voided', 'تم إلغاء هذا التحصيل من قبل', ['voided_at' => '2026-10-10T09:00:00+00:00']],
]);
