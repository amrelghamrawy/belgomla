"use client";

import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { canCancel, canCollect, nextSteps } from "@/lib/dashboard/order-status";
import { balanceOf, useCancelOrder, useTransitionOrder } from "@/lib/dashboard/orders";
import { formatMoney } from "@/lib/format/money";

import { ReasonDialog } from "../common/ReasonDialog";
import { RecordCollectionDialog } from "./RecordCollectionDialog";

const copy = ar.dashboard.orders;

/**
 * Only the actions the order's status allows (BR-O01/O02, mirrored in order-status.ts): one button per forward
 * step, the "deliver and collect in full" shortcut while money is left (BR-C04), record a collection, and
 * cancel with a reason (the backend refuses with has_collections while collections exist, BR-O03).
 */
export function OrderActions({ order }: { order: DashboardOrderDetail }) {
  const transition = useTransitionOrder(order.number);
  const cancel = useCancelOrder(order.number);
  const steps = nextSteps(order.status);
  const balance = balanceOf(order);
  const owed = balance !== "0.00";
  const collectable = canCollect(order.status) && owed;

  const move = (to: (typeof steps)[number], collect = false) =>
    transition.mutate(
      { to, collect_full_amount: collect || undefined },
      {
        onSuccess: (updated) =>
          toast.success(
            collect ? copy.deliveredAndCollected : copy.moved(ar.orders.statuses[updated.status] ?? updated.status),
          ),
        onError: (error) => toast.error(messageFor(error)),
      },
    );

  // A delivered order with money left still takes collections (BR-C04); anything else final has no actions.
  if (steps.length === 0 && !canCancel(order.status) && !collectable) {
    return <p className="text-muted-foreground text-sm">{copy.terminal}</p>;
  }

  return (
    <div className="flex flex-wrap gap-2" data-testid="order-actions">
      {steps.map((to) => (
        <Button
          key={to}
          variant={to === steps[0] ? "default" : "outline"}
          disabled={transition.isPending}
          onClick={() => move(to)}
        >
          {copy.moveTo[to]}
        </Button>
      ))}
      {steps.includes("delivered") && owed && (
        <Button
          variant="secondary"
          disabled={transition.isPending}
          title={copy.deliverAndCollectHint(formatMoney(balance))}
          onClick={() => move("delivered", true)}
        >
          {copy.deliverAndCollect}
        </Button>
      )}
      {collectable && <RecordCollectionDialog order={order} />}
      {canCancel(order.status) && (
        <ReasonDialog
          trigger={(open) => (
            <Button variant="outline" className="text-destructive" onClick={open}>
              {copy.cancel}
            </Button>
          )}
          title={copy.cancelTitle}
          description={copy.cancelBody}
          confirmLabel={copy.cancel}
          destructive
          onSubmit={async (reason) => {
            await cancel.mutateAsync(reason);
            toast.success(copy.cancelled);
          }}
        />
      )}
    </div>
  );
}
