"use client";

import type { Category } from "@/lib/api/types";
import { useRouteParam } from "@/lib/store/route";

import { ProductListing } from "./ProductListing";

/** `/c/[slug]`: the listing of one category (its subcategories included). */
export function CategoryListing({ initialCategories }: { initialCategories?: Category[] | null }) {
  const slug = useRouteParam("slug");

  return <ProductListing key={slug} category={slug} initialCategories={initialCategories} />;
}
