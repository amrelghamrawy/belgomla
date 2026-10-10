import { Suspense } from "react";

import { ReportsPage } from "@/components/dashboard/reports/ReportsPage";

export default function Page() {
  return (
    // The tab, range and grouping live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <ReportsPage />
    </Suspense>
  );
}
