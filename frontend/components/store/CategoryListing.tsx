"use client";

import { useRouteParam } from "@/lib/store/route";

import { ProductListing } from "./ProductListing";

/** `/c/[slug]`: the listing of one category (its subcategories included). */
export function CategoryListing() {
  const slug = useRouteParam("slug");

  return <ProductListing key={slug} category={slug} />;
}
