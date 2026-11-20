"use client";

import { useRouteParam } from "@/lib/store/route";

import { BundleEditor } from "./BundleEditor";

export function BundleEditPage() {
  return <BundleEditor productId={Number(useRouteParam("id"))} />;
}
