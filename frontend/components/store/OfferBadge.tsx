"use client";

import { TagIcon } from "lucide-react";

import type { PromotionSummary } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatPercent } from "@/lib/format/money";
import { timeLeft } from "@/lib/store/offer";
import { useMinuteClock } from "@/lib/store/working-hours";
import { cn } from "@/lib/utils";

/**
 * BR-PR15 (V2 Task 12): the offer badge next to a price a live promotion set: its % and «ينتهي خلال …». The countdown
 * waits for the client clock (none while server-rendering, so the markup matches) and ticks every minute.
 */
export function OfferBadge({
  promotion,
  className,
}: {
  promotion: PromotionSummary | null | undefined;
  className?: string;
}) {
  const now = useMinuteClock();

  if (!promotion) {
    return null;
  }

  const left = now === null ? undefined : timeLeft(promotion.ends_at, now);

  return (
    <span
      data-testid="offer-badge"
      className={cn(
        "bg-destructive/10 text-destructive inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5 rounded-md px-2 py-0.5 text-xs font-semibold",
        className,
      )}
    >
      <span className="inline-flex items-center gap-1">
        <TagIcon className="size-3.5" aria-hidden />
        {ar.product.offer(formatPercent(promotion.discount_percent))}
      </span>
      {left !== undefined && (
        <span className="font-normal">{left === null ? ar.product.offerEnded : ar.product.offerEndsIn(left)}</span>
      )}
    </span>
  );
}
