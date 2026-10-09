<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\CartLineInvalid;
use App\Modules\Customers\Exceptions\CustomerNotActive;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Exceptions\InvitationInvalid;
use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Exceptions\OtpAttemptsExceeded;
use App\Modules\Customers\Exceptions\OtpExpired;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Exceptions\OtpSendLimited;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Exceptions\PricesHidden;
use App\Modules\Customers\Exceptions\RegistrationRejected;
use App\Support\ApiException;

test('every customers exception fixes its status, code and arabic message', function (ApiException $e, int $status, string $code, string $message): void {
    expect([$e->status, $e->errorCode, $e->getMessage()])->toBe([$status, $code, $message]);
})->with([
    'otp wrong code' => [OtpInvalid::wrongCode(3), 422, 'otp_invalid', 'رمز التحقق غير صحيح'],
    'otp no live code' => [OtpInvalid::noLiveCode(), 422, 'otp_invalid', 'رمز التحقق غير صحيح'],
    'otp expired' => [OtpExpired::make(), 422, 'otp_expired', 'انتهت صلاحية رمز التحقق، اطلب رمزًا جديدًا'],
    'otp attempts' => [OtpAttemptsExceeded::make(), 422, 'otp_attempts_exceeded', 'تجاوزت عدد المحاولات المسموح، اطلب رمزًا جديدًا'],
    'otp hourly limit' => [OtpSendLimited::hourlyLimit(120), 429, 'rate_limited', 'طلبت رموزًا كثيرة لهذا الرقم، حاول بعد قليل'],
    'otp sms too soon' => [OtpSendLimited::smsTooSoon(20), 429, 'rate_limited', 'يمكنك طلب الرمز برسالة نصية بعد دقيقة من آخر رمز'],
    'use invitation' => [RegistrationRejected::useInvitation(), 409, 'use_invitation', 'هذا الرقم مسجل بدعوة من المتجر، استخدم رابط الدعوة المرسل إليك'],
    'phone taken' => [PhoneTaken::make(), 409, 'phone_taken', 'رقم الهاتف مسجل بالفعل'],
    'invalid credentials' => [LoginRejected::invalidCredentials(), 422, 'invalid_credentials', 'رقم الهاتف أو كلمة المرور غير صحيحة'],
    'login pending approval' => [LoginRejected::notActive(CustomerStatus::PendingApproval), 403, 'pending_approval', 'حسابك في انتظار موافقة المتجر'],
    'login blocked' => [LoginRejected::notActive(CustomerStatus::Blocked), 403, 'blocked', 'تم إيقاف حسابك، تواصل مع المتجر'],
    'login not verified' => [LoginRejected::notActive(CustomerStatus::PendingVerification), 403, 'phone_not_verified', 'لم يتم تأكيد رقم الهاتف بعد'],
    'login rate limited' => [LoginRejected::tooManyAttempts(), 429, 'rate_limited', 'محاولات كثيرة، حاول بعد دقيقة'],
    'login not stateful' => [LoginRejected::statefulRequestRequired(), 400, 'stateful_request_required', 'يجب إرسال الطلب من واجهة المتجر'],
    'invitation invalid' => [InvitationInvalid::make(), 404, 'not_found', 'رابط الدعوة غير صالح أو منتهي الصلاحية'],
    'below moq' => [CartLineInvalid::belowMoq(5, 10), 422, 'cart_line_invalid', 'الحد الأدنى للطلب 10'],
    'insufficient stock' => [CartLineInvalid::insufficientStock(5, 3), 422, 'cart_line_invalid', 'الكمية غير متوفرة'],
    'product inactive' => [CartLineInvalid::productInactive(5), 422, 'cart_line_invalid', 'المنتج غير متاح للطلب'],
    'prices hidden' => [PricesHidden::make(), 403, 'prices_hidden', 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة'],
    'transition' => [InvalidCustomerTransition::from(CustomerStatus::Active, CustomerStatus::Active), 409, 'invalid_transition', 'لا يمكن تغيير حالة العميل من «نشط» إلى «نشط»'],
    'not blocked' => [InvalidCustomerTransition::notBlocked(CustomerStatus::Active), 409, 'invalid_transition', 'العميل غير محظور، حالته «نشط»'],
    'not invitable' => [InvalidCustomerTransition::notInvitable(CustomerStatus::Active), 409, 'invalid_transition', 'لا يمكن دعوة عميل حالته «نشط»'],
    'not active' => [CustomerNotActive::for(CustomerStatus::Blocked), 403, 'blocked', 'لا يمكن إتمام هذا الإجراء، حالة حسابك «محظور»'],
]);

test('cart line failures carry the sku in items like checkout failures', function (): void {
    expect(CartLineInvalid::belowMoq(5, 10)->items)->toBe([['sku_id' => 5, 'reason' => 'below_moq', 'moq' => 10]])
        ->and(CartLineInvalid::insufficientStock(5, 3)->items)->toBe([['sku_id' => 5, 'reason' => 'insufficient_stock', 'available' => 3]])
        ->and(CartLineInvalid::productInactive(5)->items)->toBe([['sku_id' => 5, 'reason' => 'product_inactive']]);
});

test('otp send limits tell how long to wait', function (): void {
    expect(OtpSendLimited::hourlyLimit(120)->details)->toBe(['retry_after' => 120])
        ->and(OtpSendLimited::smsTooSoon(20)->details)->toBe(['retry_after' => 20])
        ->and(OtpInvalid::wrongCode(3)->details)->toBe(['attempts_left' => 3]);
});
