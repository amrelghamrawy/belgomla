"use client";

import { useInfiniteQuery, useQuery } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery, type Page } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Category, Facet, PriceListMeta, PriceListRow, StoreProduct, StoreProductDetail } from "@/lib/api/types";

import { apiParams, type Listing } from "./listing";

export const PAGE_SIZE = 24;

/** A listing rendered on the server (lib/server/store-data.ts) is fresh for a minute: no refetch on hydration. */
const LISTING_STALE_MS = 60_000;

/** The active category tree (`GET /api/store/categories`). */
export function useCategories() {
  return useQuery({
    queryKey: queryKeys.categories,
    queryFn: () => apiFetch<Category[]>("/api/store/categories"),
    staleTime: 5 * 60_000,
  });
}

/** A category anywhere in the tree, by slug. */
export function findCategory(tree: readonly Category[], slug: string): Category | null {
  for (const category of tree) {
    if (category.slug === slug) {
      return category;
    }

    const child = findCategory(category.children, slug);

    if (child !== null) {
      return child;
    }
  }

  return null;
}

export type CategoryOption = { slug: string; name: string; depth: number };

/** The tree flattened in display order (each parent before its children), for a category picker. */
export function categoryOptions(tree: readonly Category[], depth = 0): CategoryOption[] {
  return tree.flatMap((category) => [
    { slug: category.slug, name: category.name, depth },
    ...categoryOptions(category.children, depth + 1),
  ]);
}

/** Facets with counts for the selection (BR-P08), in the merchant's filter order (D18). */
export function useFilters(listing: Listing, enabled = true) {
  const params = apiParams({ ...listing, sort: undefined });

  return useQuery({
    queryKey: queryKeys.filters(params),
    queryFn: () => apiFetch<Facet[]>(withQuery("/api/store/filters", params)),
    placeholderData: (previous) => previous,
    enabled,
  });
}

/** The product grid, 24 per page, loaded page after page as the shopper scrolls. */
export function useProductsInfinite(listing: Listing, enabled = true) {
  const params = apiParams(listing);

  return useInfiniteQuery({
    queryKey: queryKeys.products(params),
    queryFn: ({ pageParam, signal }) =>
      apiFetchPage<StoreProduct>(
        withQuery("/api/store/products", { ...params, page: pageParam, per_page: PAGE_SIZE }),
        {
          signal,
        },
      ),
    initialPageParam: 1,
    getNextPageParam: (last: Page<StoreProduct>) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    staleTime: LISTING_STALE_MS,
    enabled,
  });
}

/** The newest active products for the home page. */
export function useFeaturedProducts() {
  const params = { sort: "-created_at", per_page: 8 } as const;

  return useQuery({
    queryKey: queryKeys.products(params),
    queryFn: () => apiFetchPage<StoreProduct>(withQuery("/api/store/products", params)),
  });
}

/** A category's newest products, for its row on the home page (D67). */
export function useCategoryProducts(slug: string) {
  const params = { category: slug, sort: "-created_at", per_page: 8 } as const;

  return useQuery({
    queryKey: queryKeys.products(params),
    queryFn: () => apiFetchPage<StoreProduct>(withQuery("/api/store/products", params)),
  });
}

export function useProduct(slug: string) {
  return useQuery({
    queryKey: queryKeys.product(slug),
    queryFn: () => apiFetch<StoreProductDetail>(`/api/store/products/${encodeURIComponent(slug)}`),
  });
}

/** The printable price list for a selection (BR-P13): every row at once, capped by the backend. */
export function usePriceList(listing: Listing) {
  const params = apiParams({ ...listing, sort: undefined });

  return useQuery({
    queryKey: queryKeys.priceList(params),
    queryFn: () => apiFetchPage<PriceListRow, PriceListMeta>(withQuery("/api/store/price-list", params)),
  });
}
