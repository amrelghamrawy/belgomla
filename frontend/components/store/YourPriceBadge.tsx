import { BadgeCheckIcon } from "lucide-react";

import type { PriceSource } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

/**
 * BR-PR14 (V2 Task 11): «سعرك الخاص» next to a price that is the shopper's own: their group's price or the one
 * negotiated with them. Base and tier prices (and guests) show nothing.
 */
export function YourPriceBadge({ source, className }: { source: PriceSource | null | undefined; className?: string }) {
  if (source !== "group" && source !== "customer") {
    return null;
  }

  return (
    <span
      data-testid="your-price"
      className={cn(
        "bg-brand-soft text-primary inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold",
        className,
      )}
    >
      <BadgeCheckIcon className="size-3.5" aria-hidden />
      {ar.product.yourPrice}
    </span>
  );
}
