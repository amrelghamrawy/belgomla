import { Suspense } from "react";

import { NewOrderPage } from "@/components/dashboard/orders/NewOrderPage";

export default function Page() {
  return (
    // BR-O11: `?customer=` preselects the customer (useSearchParams needs a Suspense boundary).
    <Suspense>
      <NewOrderPage />
    </Suspense>
  );
}
