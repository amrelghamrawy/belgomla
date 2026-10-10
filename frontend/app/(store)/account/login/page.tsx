import { Suspense } from "react";

import { AuthPage } from "@/components/store/AuthCard";
import { CustomerLoginForm } from "@/components/store/CustomerLoginForm";

export default function CustomerLoginPage() {
  return (
    <AuthPage>
      {/* The form reads ?next=; useSearchParams needs a Suspense boundary under cacheComponents. */}
      <Suspense>
        <CustomerLoginForm />
      </Suspense>
    </AuthPage>
  );
}
