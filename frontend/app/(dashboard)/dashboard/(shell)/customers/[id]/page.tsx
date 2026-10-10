import { Suspense } from "react";

import { CustomerDetailPage } from "@/components/dashboard/customers/CustomerDetailPage";

export default function Page() {
  return (
    // The customer id and the tab are request-time data under cacheComponents.
    <Suspense>
      <CustomerDetailPage />
    </Suspense>
  );
}
