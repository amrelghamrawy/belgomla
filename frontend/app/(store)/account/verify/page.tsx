import { Suspense } from "react";

import { AuthPage } from "@/components/store/AuthCard";
import { OtpVerifyForm } from "@/components/store/OtpVerifyForm";

export default function VerifyPage() {
  return (
    <AuthPage>
      {/* Reads ?phone=&purpose=&via= (Suspense under cacheComponents). */}
      <Suspense>
        <OtpVerifyForm />
      </Suspense>
    </AuthPage>
  );
}
