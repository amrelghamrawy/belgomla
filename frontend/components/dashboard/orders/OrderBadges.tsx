import { Badge } from "@/components/ui/badge";
import type { CollectionStatus, OrderStatus, PreorderState } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { expiryLeft, useMinuteClock } from "@/lib/dashboard/order-expiry";
import { cn } from "@/lib/utils";

import { Hint } from "../common/Hint";

/**
 * D104: each status is a soft tint with a readable ink of the same hue: waiting amber, confirmed blue, in hand
 * brand, on the road solid brand, done green, cancelled red. The label always carries the state, never the colour alone.
 */
const ORDER_TONES: Record<OrderStatus, string> = {
  pending: "bg-warn-soft text-warn",
  confirmed: "bg-info-soft text-info",
  preparing: "bg-primary-soft text-primary",
  out_for_delivery: "bg-primary text-primary-foreground",
  delivered: "bg-ok-soft text-ok",
  cancelled: "bg-danger-soft text-destructive",
};

/** D104: collection state in the same tints: red unpaid, amber partly collected, green collected. */
const COLLECTION_TONES: Record<CollectionStatus, string> = {
  unpaid: "bg-danger-soft text-destructive",
  partially_collected: "bg-warn-soft text-warn",
  collected: "bg-ok-soft text-ok",
};

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  return (
    <Badge variant="outline" className={cn("border-transparent", ORDER_TONES[status])} data-testid="order-status">
      {ar.orders.statuses[status]}
    </Badge>
  );
}

/** BR-C04: collection status is separate from order status. */
export function CollectionStatusBadge({ status }: { status: CollectionStatus }) {
  return (
    <Badge variant="outline" className={cn("border-transparent", COLLECTION_TONES[status])} data-testid="collection-status">
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
      <Badge variant="outline" className="bg-warn-soft text-warn border-transparent" tabIndex={0}>
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
