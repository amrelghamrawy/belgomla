"use client";

import { SearchXIcon } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useMemo } from "react";

import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import type { Category } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { categoryPath, useCategories, useFilters, useProductsInfinite } from "@/lib/store/catalog";
import { listingSearch, parseListing, type Listing } from "@/lib/store/listing";

import { CategoryBreadcrumbs } from "./CategoryBreadcrumbs";
import { FacetDrawer } from "./FacetDrawer";
import { LoadMore } from "./LoadMore";
import { ProductGrid, ProductGridSkeleton } from "./ProductGrid";
import { SortSelect } from "./SortSelect";

type ProductListingProps =
  | {
      category: string;
      /** The tree the server prefetched (prefetchListing): rendered until the hydrated cache takes over. */
      initialCategories?: Category[] | null | undefined;
    }
  | { search: true };

/**
 * A category page (`/c/[slug]`) or the search results (`/search?q=`): facets, sort and the product grid. The
 * selection lives in the URL (BR-P08), so every state can be shared and the back button undoes a filter.
 */
export function ProductListing(props: ProductListingProps) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const categorySlug = "category" in props ? props.category : undefined;
  const listing = useMemo(() => parseListing(searchParams, categorySlug), [searchParams, categorySlug]);

  const categories = useCategories();
  // The server and the first client render must agree: on the client the header may already have fetched the
  // tree while the prefetched one is still waiting for HydrationBoundary's effect, so both fall back to the prop.
  const tree = categories.data ?? ("category" in props ? (props.initialCategories ?? undefined) : undefined);
  const path = categorySlug !== undefined && tree ? categoryPath(tree, categorySlug) : null;
  const category = path?.at(-1) ?? null;
  const searching = "search" in props;
  const searchReady = !searching || listing.q !== undefined;

  const filters = useFilters(listing, searchReady);
  const products = useProductsInfinite(listing, searchReady);
  const rows = products.data?.pages.flatMap((page) => page.data) ?? [];
  const total = products.data?.pages[0]?.meta.total;

  const navigate = useCallback(
    (next: Omit<Listing, "category">) => router.replace(`${pathname}${listingSearch(next)}`, { scroll: false }),
    [pathname, router],
  );
  const loadMore = useCallback(() => void products.fetchNextPage(), [products]);

  if (categorySlug !== undefined && tree !== undefined && category === null) {
    return <StatusPage title={ar.catalog.categoryNotFound} action={<BackHome />} />;
  }

  const title = searching
    ? listing.q === undefined
      ? ar.catalog.searchEmpty
      : ar.catalog.searchTitle(listing.q)
    : (category?.name ?? ar.loading);

  return (
    <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 px-4 py-4">
      {path !== null && <CategoryBreadcrumbs path={path} />}

      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-bold">{title}</h1>
        {total !== undefined && <p className="text-muted-foreground text-sm">{ar.catalog.resultsCount(total)}</p>}
      </div>

      {category !== null && category.children.length > 0 && (
        <nav aria-label={ar.store.categories} className="-mx-4 overflow-x-auto px-4">
          <ul className="flex gap-2">
            {category.children.map((child) => (
              <li key={child.id} className="shrink-0">
                <Link
                  href={`/c/${encodeURIComponent(child.slug)}`}
                  className="hover:border-primary flex h-9 items-center rounded-full border px-4 text-sm"
                >
                  {child.name}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      )}

      {searchReady && (
        <div className="flex flex-wrap items-center gap-2">
          <FacetDrawer
            facets={filters.data ?? []}
            selection={listing.f}
            total={total}
            onChange={(f) => navigate({ ...listing, f })}
          />
          <SortSelect value={listing.sort} onChange={(sort) => navigate({ ...listing, sort })} />
        </div>
      )}

      {!searchReady ? null : products.isError ? (
        <p role="alert" className="text-destructive">
          {messageFor(products.error)}
        </p>
      ) : products.data === undefined ? (
        <ProductGridSkeleton />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 py-12 text-center">
          <SearchXIcon className="text-muted-foreground size-10" aria-hidden />
          <p className="font-bold">{ar.catalog.noResults}</p>
          <p className="text-muted-foreground text-sm">{ar.catalog.noResultsHint}</p>
        </div>
      ) : (
        <>
          <ProductGrid products={rows} />
          <LoadMore hasMore={products.hasNextPage} loading={products.isFetchingNextPage} onLoad={loadMore} />
        </>
      )}
    </main>
  );
}

function BackHome() {
  return (
    <Button asChild size="lg" className="h-11 px-6">
      <Link href="/">{ar.pages.backHome}</Link>
    </Button>
  );
}
