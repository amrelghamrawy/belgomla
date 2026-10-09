<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Data\RegistrationData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Exceptions\RegistrationRejected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Messaging\Enums\Channel;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU04: storefront self-registration. Creates the customer as `pending_verification` and sends the OTP
 * (BR-CU07). A phone the merchant invited is sent to the invitation link (`409 use_invitation`, BR-CU08); a
 * phone still waiting for its OTP registers again with the new name and password and gets a new code (D52);
 * any other existing phone is `409 phone_taken`. Owns its transaction.
 */
final readonly class RegisterCustomer
{
    public function __construct(private SendOtp $sendOtp) {}

    public function handle(RegistrationData $data): Channel
    {
        $existing = Customer::query()->where('phone', $data->phone)->first();

        if ($existing instanceof Customer && $existing->status === CustomerStatus::Invited) {
            throw RegistrationRejected::useInvitation();
        }

        if ($existing instanceof Customer && $existing->status !== CustomerStatus::PendingVerification) {
            throw PhoneTaken::make();
        }

        return DB::transaction(function () use ($existing, $data): Channel {
            $customer = $existing ?? new Customer(['phone' => $data->phone, 'status' => CustomerStatus::PendingVerification]);
            $customer->fill(['name' => $data->name, 'password' => $data->password])->save();

            return $this->sendOtp->handle($data->phone, OtpPurpose::Register);
        });
    }
}
