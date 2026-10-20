import { Badge } from "@/components/ui/badge";
import type { CollectionStatus, OrderStatus, PreorderState } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { expiryLeft, useMinuteClock } from "@/lib/dashboard/order-expiry";
import { cn } from "@/lib/utils";

import { Hint } from "../common/Hint";

const ORDER_TONES: Record<OrderStatus, string> = {
  pending: "bg-amber-100 text-amber-900",
  confirmed: "bg-sky-100 text-sky-900",
  preparing: "bg-indigo-100 text-indigo-900",
  out_for_delivery: "bg-violet-100 text-violet-900",
  delivered: "bg-emerald-100 text-emerald-900",
  cancelled: "bg-muted text-muted-foreground",
};

const COLLECTION_TONES: Record<CollectionStatus, string> = {
  unpaid: "border-destructive/40 text-destructive",
  partially_collected: "border-amber-500 text-amber-900",
  collected: "border-emerald-600 text-emerald-800",
};

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  return (
    <Badge variant="secondary" className={cn("border-transparent", ORDER_TONES[status])} data-testid="order-status">
      {ar.orders.statuses[status]}
    </Badge>
  );
}

/** BR-C04: collection status is separate from order status. */
export function CollectionStatusBadge({ status }: { status: CollectionStatus }) {
  return (
    <Badge variant="outline" className={COLLECTION_TONES[status]} data-testid="collection-status">
      {ar.dashboard.collectionStatuses[status]}
    </Badge>
  );
}

/** D81: how long a pending order has before it cancels itself, ticking every minute. */
export function ExpiryBadge({ expiresAt }: { expiresAt: string }) {
  const left = expiryLeft(expiresAt, useMinuteClock());

  return (
    <Hint text={ar.dashboard.orders.expiryHint}>
      <Badge variant="outline" className="border-destructive/40 text-destructive" tabIndex={0} data-testid="expiry">
        {ar.dashboard.orders.expiresIn(left.hours, left.minutes)}
      </Badge>
    </Hint>
  );
}

/** BR-O11: the order was placed from the dashboard on the customer's behalf. */
export function PlacedByMerchantBadge() {
  return (
    <Hint text={ar.dashboard.orders.placedByMerchantHint}>
      <Badge variant="outline" tabIndex={0} data-testid="placed-by-merchant">
        {ar.dashboard.orders.placedByMerchant}
      </Badge>
    </Hint>
  );
}

export function StaleBadge() {
  return (
    <Hint text={ar.dashboard.orders.staleHint}>
      <Badge variant="outline" className="border-amber-500 text-amber-900" tabIndex={0}>
        {ar.dashboard.orders.stale}
      </Badge>
    </Hint>
  );
}

/** BR-O14, D88: a pre-order, with where it stands (open, converted, cancelled), instead of a status. */
export function PreorderBadge({ state }: { state: PreorderState }) {
  return (
    <Hint text={ar.dashboard.orders.preorderHint}>
      <Badge
        variant="outline"
        className={cn("border-primary text-primary", state === "cancelled" && "border-muted text-muted-foreground")}
        tabIndex={0}
        data-testid="preorder-badge"
      >
        {ar.dashboard.orders.preorder} · {ar.dashboard.orders.preorderStates[state]}
      </Badge>
    </Hint>
  );
}
