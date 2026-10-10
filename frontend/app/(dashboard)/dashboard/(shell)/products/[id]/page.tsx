import { Suspense } from "react";

import { ProductEditPage } from "@/components/dashboard/catalog/ProductEditPage";

export default function Page() {
  return (
    // The product id is request-time data under cacheComponents (useParams suspends).
    <Suspense>
      <ProductEditPage />
    </Suspense>
  );
}
