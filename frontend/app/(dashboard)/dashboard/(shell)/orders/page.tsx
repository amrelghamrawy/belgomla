import { Suspense } from "react";

import { OrdersPage } from "@/components/dashboard/orders/OrdersPage";

export default function Page() {
  return (
    // Status tab, filters and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <OrdersPage />
    </Suspense>
  );
}
