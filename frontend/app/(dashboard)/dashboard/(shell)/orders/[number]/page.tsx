import { Suspense } from "react";

import { OrderDetailPage } from "@/components/dashboard/orders/OrderDetailPage";

export default function Page() {
  return (
    // The order number is request-time data under cacheComponents (useParams suspends).
    <Suspense>
      <OrderDetailPage />
    </Suspense>
  );
}
