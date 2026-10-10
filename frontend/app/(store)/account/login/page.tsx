import { Suspense } from "react";

import { CustomerLoginForm } from "@/components/store/CustomerLoginForm";

export default function CustomerLoginPage() {
  return (
    <main className="flex flex-1 items-center justify-center p-4">
      {/* The form reads ?next=; useSearchParams needs a Suspense boundary under cacheComponents. */}
      <Suspense>
        <CustomerLoginForm />
      </Suspense>
    </main>
  );
}
