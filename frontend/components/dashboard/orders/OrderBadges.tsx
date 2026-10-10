import { Badge } from "@/components/ui/badge";
import type { CollectionStatus, OrderStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

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

export function StaleBadge() {
  return (
    <Badge variant="outline" className="border-amber-500 text-amber-900" title={ar.dashboard.orders.staleHint}>
      {ar.dashboard.orders.stale}
    </Badge>
  );
}
