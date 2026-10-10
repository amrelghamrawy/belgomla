import Link from "next/link";

import { Badge } from "@/components/ui/badge";
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
 * Wholesale price, the cover price struck through and the implied discount (BR-PR10). When the store hides prices
 * from guests the same box shows a login link, so the layout does not shift either way.
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

  return (
    <div className={cn("flex flex-col justify-center", large ? "min-h-16 gap-1" : "min-h-11")}>
      {large && <span className="text-muted-foreground text-xs">{ar.product.wholesalePrice}</span>}
      <div className="flex flex-wrap items-center gap-2">
        <span className={cn("font-bold", large ? "text-2xl" : "text-base")}>{formatMoney(sku.base_price)}</span>
        {sku.discount_percent && (
          <Badge variant="secondary" className="text-primary">
            {ar.product.discount(formatPercent(sku.discount_percent))}
          </Badge>
        )}
      </div>
      {sku.cover_price && (
        <span className="text-muted-foreground text-xs">
          {ar.product.coverPrice}: <s>{formatMoney(sku.cover_price)}</s>
        </span>
      )}
    </div>
  );
}
