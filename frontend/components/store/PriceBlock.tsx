import { TrendingUpIcon } from "lucide-react";
import Link from "next/link";

import type { StoreSku } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney, formatPercent } from "@/lib/format/money";
import { cn } from "@/lib/utils";

type PriceBlockProps = {
  sku: StoreSku | null;
  pricesHidden: boolean;
  size?: "sm" | "lg";
  /** Where the login link returns to when prices are hidden (D1). */
  loginNext?: string;
};

/**
 * Wholesale price per selling unit, the retailer's margin (the discount off the cover price, BR-PR10, shown as
 * "هامش ربحك" per D67), the cover price struck through and, on cards, the first quantity tier. When the store hides
 * prices from guests the same box shows a login link, so the layout does not shift either way.
 */
export function PriceBlock({ sku, pricesHidden, size = "sm", loginNext }: PriceBlockProps) {
  const large = size === "lg";

  if (pricesHidden || sku?.base_price === undefined) {
    return (
      <div className={cn("flex items-center", large ? "min-h-16" : "min-h-11")}>
        <Link
          href={`/account/login${loginNext ? `?next=${encodeURIComponent(loginNext)}` : ""}`}
          className="text-primary text-sm font-medium underline-offset-4 hover:underline"
        >
          {ar.catalog.loginForPrices}
        </Link>
      </div>
    );
  }

  const tier = large ? undefined : sku.tiers?.[0];

  return (
    <div className={cn("flex flex-col items-start", large ? "min-h-16 gap-1.5" : "gap-1")}>
      {large && <span className="text-muted-foreground text-xs">{ar.product.wholesalePrice}</span>}
      <div className="flex flex-wrap items-baseline gap-x-1.5">
        <span className={cn("font-bold tracking-tight tabular-nums", large ? "text-3xl" : "text-xl")}>
          {formatMoney(sku.base_price)}
        </span>
        {sku.unit !== null && <span className="text-muted-foreground text-xs">/ {sku.unit}</span>}
      </div>
      {sku.discount_percent && (
        <span className="bg-highlight-soft text-foreground inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold">
          <TrendingUpIcon className="size-3.5" aria-hidden />
          {ar.product.margin(formatPercent(sku.discount_percent))}
        </span>
      )}
      {sku.cover_price && (
        <span className="text-muted-foreground text-xs">
          {ar.product.coverPrice}: <s className="tabular-nums">{formatMoney(sku.cover_price)}</s>
        </span>
      )}
      {tier?.unit_price && (
        <span className="text-primary text-xs font-semibold">
          {ar.product.tierShort(tier.min_quantity, sku.unit ?? "", formatMoney(tier.unit_price))}
        </span>
      )}
    </div>
  );
}
