import { Suspense } from "react";

import { InventoryPage } from "@/components/dashboard/inventory/InventoryPage";

export default function Page() {
  return (
    // Search, sort, low-stock and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <InventoryPage />
    </Suspense>
  );
}
