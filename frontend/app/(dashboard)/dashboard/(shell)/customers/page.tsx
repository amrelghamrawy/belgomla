import { Suspense } from "react";

import { CustomersPage } from "@/components/dashboard/customers/CustomersPage";

export default function Page() {
  return (
    // Search, status and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <CustomersPage />
    </Suspense>
  );
}
