import { Suspense } from "react";

import { ProductGridSkeleton } from "@/components/store/ProductGrid";
import { ProductListing } from "@/components/store/ProductListing";

export default function SearchPage() {
  return (
    <Suspense
      fallback={
        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-4">
          <ProductGridSkeleton />
        </main>
      }
    >
      <ProductListing search />
    </Suspense>
  );
}
