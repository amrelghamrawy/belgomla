"use client";

import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { allowsPriceChange, canCancel, canCollect, nextSteps } from "@/lib/dashboard/order-status";
import { balanceOf, useCancelOrder, useTransitionOrder } from "@/lib/dashboard/orders";
import { useCan, useIsWarehouse } from "@/lib/dashboard/permissions";
import { formatMoney } from "@/lib/format/money";

import { Hint } from "../common/Hint";
import { ReasonDialog } from "../common/ReasonDialog";
import { ApplyCreditDialog } from "./ApplyCreditDialog";
import { DiscountDialog } from "./DiscountDialog";
import { RecordCollectionDialog } from "./RecordCollectionDialog";
import { ReturnDialog } from "./ReturnDialog";

const copy = ar.dashboard.orders;

/**
 * Only the actions the order's status allows (BR-O01/O02, mirrored in order-status.ts): one button per forward
 * step, the "deliver and collect in full" shortcut while money is left (BR-C04), record a collection, record a
 * return on a delivered order (BR-R01), discount a pending or confirmed order (BR-PR08, `orders.discount`), and cancel with a reason (the backend refuses with has_collections while
 * collections exist, BR-O03). Each only when the role allows it (D82): the warehouse prepares and sends out but
 * neither confirms, delivers nor cancels; collecting needs `collections.manage`, returns `orders.returns`.
 */
export function OrderActions({ order }: { order: DashboardOrderDetail }) {
  const transition = useTransitionOrder(order.number);
  const cancel = useCancelOrder(order.number);
  const allowed = useCan();
  const warehouse = useIsWarehouse();
  const manages = allowed("orders.manage");
  const collects = allowed("collections.manage");
  const steps = manages
    ? nextSteps(order.status).filter((to) => !warehouse || to === "preparing" || to === "out_for_delivery")
    : [];
  const cancellable = manages && !warehouse && canCancel(order.status);
  const balance = balanceOf(order);
  const owed = balance !== "0.00";
  const collectable = collects && canCollect(order.status) && owed;
  const returnable = allowed("orders.returns") && order.status === "delivered";
  const discountable = allowed("orders.discount") && allowsPriceChange(order.status);

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

  // A delivered order still takes collections (BR-C04) and returns (BR-R01); a cancelled one has no actions.
  if (steps.length === 0 && !cancellable && !collectable && !returnable && !discountable) {
    return canCancel(order.status) || order.status === "delivered" ? null : (
      <p className="text-muted-foreground text-sm">{copy.terminal}</p>
    );
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
      {steps.includes("delivered") && owed && collects && (
        <Hint text={copy.deliverAndCollectHint(formatMoney(balance))}>
          <Button variant="secondary" disabled={transition.isPending} onClick={() => move("delivered", true)}>
            {copy.deliverAndCollect}
          </Button>
        </Hint>
      )}
      {discountable && <DiscountDialog order={order} />}
      {collectable && <RecordCollectionDialog order={order} />}
      {collectable && <ApplyCreditDialog order={order} />}
      {returnable && <ReturnDialog orderNumber={order.number} />}
      {cancellable && (
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
