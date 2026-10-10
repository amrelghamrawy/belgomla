import { Suspense } from "react";

import { DashboardLoginForm } from "@/components/dashboard/DashboardLoginForm";

export default function DashboardLoginPage() {
  return (
    <main className="flex flex-1 items-center justify-center p-4">
      {/* The form reads ?next=; useSearchParams needs a Suspense boundary under cacheComponents. */}
      <Suspense>
        <DashboardLoginForm />
      </Suspense>
    </main>
  );
}
