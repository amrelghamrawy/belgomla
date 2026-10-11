"use client";

import { PrinterIcon, Share2Icon } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useCallback, useMemo } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useTenant } from "@/lib/auth/tenant";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { findCategory, useCategories, usePriceList } from "@/lib/store/catalog";
import { parseListing, priceListHref, type Listing } from "@/lib/store/listing";

import { PriceListFilters } from "./PriceListFilters";

/**
 * Copies the page URL, which carries the whole selection, so the list can be sent as a link (BR-P13). The Clipboard
 * API exists only on https; a store opened over http (dev, a LAN preview) falls back to a hidden textarea.
 */
export async function copyPageLink(): Promise<void> {
  const url = window.location.href;

  try {
    if (navigator.clipboard !== undefined) {
      await navigator.clipboard.writeText(url);
    } else if (!copyWithSelection(url)) {
      throw new Error("copy refused");
    }

    toast.success(ar.priceList.copied);
  } catch {
    toast.error(ar.priceList.copyFailed);
  }
}

function copyWithSelection(text: string): boolean {
  const field = document.createElement("textarea");
  field.value = text;
  field.setAttribute("readonly", "");
  field.style.position = "fixed";
  field.style.opacity = "0";
  document.body.append(field);
  field.select();

  try {
    // Deprecated, but the only copy that works without https.
    return document.execCommand("copy");
  } finally {
    field.remove();
  }
}

/**
 * `/price-list?category=&f[...]=&q=`: a print-friendly table of every product in the selection (BR-P13): name,
 * code, unit, reference and wholesale price, MOQ, availability. The reference column is named after the chosen
 * category's section (سعر الغلاف for books, سعر القطاعي otherwise, D68) and both ways when the list mixes
 * sections. The browser's print-to-PDF is the V1 export.
 */
export function PriceListView() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const listing = useMemo(() => parseListing(searchParams, searchParams.get("category") ?? undefined), [searchParams]);
  const { data: tenant } = useTenant();
  const categories = useCategories();
  const list = usePriceList(listing);
  const category = listing.category && categories.data ? findCategory(categories.data, listing.category) : null;
  const filtered = Object.keys(listing.f).length > 0 || listing.q !== undefined;
  const pricesHidden = list.data?.meta.prices_hidden === true;
  const navigate = useCallback((next: Listing) => router.replace(priceListHref(next), { scroll: false }), [router]);

  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 px-4 py-4 print:max-w-none print:p-0">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-bold">
            {ar.priceList.title}
            {category ? ` — ${category.name}` : ""}
          </h1>
          <p className="text-muted-foreground text-sm" data-testid="price-list-meta">
            {tenant?.name}
            {tenant?.phone ? " · " : ""}
            {tenant?.phone && <bdi>{tenant.phone}</bdi>} ·{" "}
            {ar.priceList.printedOn(formatDate(new Date().toISOString()))}
            {filtered ? ` · ${ar.priceList.filtered}` : ""}
          </p>
        </div>
        <div className="flex gap-2 print:hidden">
          <Button variant="outline" className="h-11" onClick={() => void copyPageLink()}>
            <Share2Icon />
            {ar.priceList.share}
          </Button>
          <Button className="h-11" onClick={() => window.print()}>
            <PrinterIcon />
            {ar.priceList.print}
          </Button>
        </div>
      </div>

      <PriceListFilters
        listing={listing}
        categories={categories.data ?? []}
        total={list.data?.meta.count}
        onChange={navigate}
      />

      {pricesHidden && (
        <Alert className="print:hidden">
          <AlertDescription>
            <Link href={`/account/login?next=${encodeURIComponent("/price-list")}`} className="text-primary underline">
              {ar.priceList.pricesHidden}
            </Link>
          </AlertDescription>
        </Alert>
      )}

      {list.data?.meta.truncated && (
        <Alert>
          <AlertDescription>{ar.priceList.truncated(list.data.meta.count)}</AlertDescription>
        </Alert>
      )}

      {list.isError ? (
        <p role="alert" className="text-destructive">
          {messageFor(list.error)}
        </p>
      ) : list.data === undefined ? (
        <Skeleton className="h-96 w-full" />
      ) : list.data.data.length === 0 ? (
        <p className="text-muted-foreground py-8 text-center">{ar.priceList.empty}</p>
      ) : (
        <div className="overflow-x-auto print:overflow-visible">
          <table
            className="w-full min-w-[640px] border-collapse text-sm print:min-w-0 print:text-xs"
            data-testid="price-list"
          >
            <thead>
              <tr className="bg-muted/60 print:bg-transparent">
                <th scope="col" className="border-b p-2 text-start">
                  {ar.priceList.name}
                </th>
                <th scope="col" className="border-b p-2 text-start">
                  {ar.priceList.code}
                </th>
                <th scope="col" className="border-b p-2 text-start">
                  {ar.priceList.unit}
                </th>
                {!pricesHidden && (
                  <th scope="col" className="border-b p-2 text-start">
                    {ar.product.referencePrice(category?.kind ?? null)}
                  </th>
                )}
                {!pricesHidden && (
                  <th scope="col" className="border-b p-2 text-start">
                    {ar.priceList.wholesale}
                  </th>
                )}
                <th scope="col" className="border-b p-2 text-start">
                  {ar.priceList.moq}
                </th>
                <th scope="col" className="border-b p-2 text-start">
                  {ar.priceList.availability}
                </th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((row) => (
                <tr key={row.id} className="break-inside-avoid border-b">
                  <td className="p-2">
                    <Link href={`/p/${encodeURIComponent(row.slug)}`} className="hover:underline print:no-underline">
                      {row.name}
                    </Link>
                  </td>
                  <td className="p-2 whitespace-nowrap">
                    <bdi>{row.code}</bdi>
                  </td>
                  <td className="p-2 whitespace-nowrap">{row.unit}</td>
                  {!pricesHidden && (
                    <td className="p-2 whitespace-nowrap">{row.cover_price ? formatMoney(row.cover_price) : "—"}</td>
                  )}
                  {!pricesHidden && (
                    <td className="p-2 font-medium whitespace-nowrap">
                      {row.base_price ? formatMoney(row.base_price) : "—"}
                    </td>
                  )}
                  <td className="p-2">{row.moq}</td>
                  <td className="p-2">
                    {row.availability === "coming_soon" ? ar.priceList.comingSoon : ar.priceList.available}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </main>
  );
}
