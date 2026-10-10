import { Suspense } from "react";

import { AuthPage } from "@/components/store/AuthCard";
import { RegisterForm } from "@/components/store/RegisterForm";

export default function RegisterPage() {
  return (
    <AuthPage>
      {/* Reads ?next= (Suspense under cacheComponents). */}
      <Suspense>
        <RegisterForm />
      </Suspense>
    </AuthPage>
  );
}
