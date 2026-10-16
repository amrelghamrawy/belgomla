import { HydrationBoundary } from "@tanstack/react-query";
import { Suspense } from "react";

import { CategoryListing } from "@/components/store/CategoryListing";
import { ProductGridSkeleton } from "@/components/store/ProductGrid";
import { prefetchListing, toSearchParams } from "@/lib/server/store-data";
import { parseListing } from "@/lib/store/listing";

export default function CategoryPage({ params, searchParams }: PageProps<"/c/[slug]">) {
  return (
    // The slug and the facet selection are request-time data under cacheComponents: the shell (header, skeleton)
    // is static, the listing streams in with its first page already rendered.
    <Suspense fallback={<ListingFallback />}>
      <PrefetchedCategory params={params} searchParams={searchParams} />
    </Suspense>
  );
}

async function PrefetchedCategory({ params, searchParams }: PageProps<"/c/[slug]">) {
  const [{ slug }, query] = await Promise.all([params, searchParams]);
  const { state, categories } = await prefetchListing(parseListing(toSearchParams(query), safeDecode(slug)));

  return (
    <HydrationBoundary state={state}>
      <CategoryListing initialCategories={categories} />
    </HydrationBoundary>
  );
}

function safeDecode(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function ListingFallback() {
  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-4">
      <ProductGridSkeleton />
    </main>
  );
}
