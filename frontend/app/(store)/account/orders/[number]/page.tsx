import { Suspense } from "react";

import { OrderDetailView } from "@/components/store/OrderDetailView";

export default function OrderPage() {
  return (
    // The order number is request-time data under cacheComponents (useParams suspends).
    <Suspense>
      <OrderDetailView />
    </Suspense>
  );
}
