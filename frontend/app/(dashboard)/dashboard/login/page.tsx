import { Suspense } from "react";

import { DashboardLoginForm } from "@/components/dashboard/DashboardLoginForm";
import { ar } from "@/lib/copy/ar";

export default function DashboardLoginPage() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-6 p-4">
      {/* D104: the platform's mark over the sign-in; the store's identity starts once inside. */}
      <p className="flex items-center gap-2.5" aria-hidden>
        <span className="bg-primary text-primary-foreground font-heading flex size-10 items-center justify-center rounded-lg text-xl font-extrabold">
          {ar.appName.slice(0, 1)}
        </span>
        <span className="font-heading text-primary text-2xl font-extrabold">{ar.appName}</span>
      </p>
      {/* The form reads ?next=; useSearchParams needs a Suspense boundary under cacheComponents. */}
      <Suspense>
        <DashboardLoginForm />
      </Suspense>
    </main>
  );
}
