import { Suspense } from "react";

import { AuditPage } from "@/components/dashboard/admin/AuditPage";

export default function Page() {
  return (
    // Filters and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <AuditPage />
    </Suspense>
  );
}
