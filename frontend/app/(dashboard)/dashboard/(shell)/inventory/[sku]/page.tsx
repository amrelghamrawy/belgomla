import { Suspense } from "react";

import { MovementsPage } from "@/components/dashboard/inventory/MovementsPage";

export default function Page() {
  return (
    // The SKU id and the page are request-time data under cacheComponents.
    <Suspense>
      <MovementsPage />
    </Suspense>
  );
}
