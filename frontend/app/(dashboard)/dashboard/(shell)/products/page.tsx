import { Suspense } from "react";

import { ProductsPage } from "@/components/dashboard/catalog/ProductsPage";

export default function Page() {
  return (
    // Filters, sort and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <ProductsPage />
    </Suspense>
  );
}
