"use client";

import { useRouteParam } from "@/lib/store/route";

import { ProductEditor } from "./ProductEditor";

export function ProductEditPage() {
  return <ProductEditor productId={Number(useRouteParam("id"))} />;
}
