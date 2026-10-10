import { Suspense } from "react";

import { ProductDetail } from "@/components/store/ProductDetail";

export default function ProductPage() {
  return (
    // The slug is request-time data under cacheComponents (useParams suspends).
    <Suspense>
      <ProductDetail />
    </Suspense>
  );
}
