"use client";

import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { allowsLineEdit, allowsPriceChange, canCancel, canCollect, nextSteps } from "@/lib/dashboard/order-status";
import { balanceOf, useCancelOrder, useTransitionOrder } from "@/lib/dashboard/orders";
import { useCan, useIsWarehouse } from "@/lib/dashboard/permissions";
import { formatMoney } from "@/lib/format/money";

import { ReasonDialog } from "../common/ReasonDialog";
import { ApplyCreditDialog } from "./ApplyCreditDialog";
import { DeliverDialog } from "./DeliverDialog";
import { DiscountDialog } from "./DiscountDialog";
import { EditLinesDialog } from "./EditLinesDialog";
import { RecordCollectionDialog } from "./RecordCollectionDialog";
import { ReturnDialog } from "./ReturnDialog";

const copy = ar.dashboard.orders;

/**
 * Only the actions the order's status allows (BR-O01/O02, mirrored in order-status.ts): one button per forward
 * step, the "deliver and collect in full" shortcut while money is left (BR-C04), record a collection, record a
 * return on a delivered order (BR-R01), discount a pending or confirmed order (BR-PR08, `orders.discount`), edit its lines
 * (BR-O12, `orders.edit`), deliver through a confirmation that can record a partial delivery (BR-O13), and cancel with a reason (the backend refuses with has_collections while
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
  // BR-O14, D88: a pre-order is only cancelled (while something is left); it is converted from the product page.
  const preorder = order.kind === "preorder";
  const steps =
    manages && !preorder
      ? nextSteps(order.status).filter((to) => !warehouse || to === "preparing" || to === "out_for_delivery")
      : [];
  const cancellable = manages && !warehouse && canCancel(order.status) && order.preorder_state !== "converted";
  const balance = balanceOf(order);
  const owed = balance !== "0.00";
  const collectable = collects && !preorder && canCollect(order.status) && owed;
  const returnable = allowed("orders.returns") && order.status === "delivered";
  const discountable = allowed("orders.discount") && !preorder && allowsPriceChange(order.status);
  const editable = allowed("orders.edit") && !preorder && allowsLineEdit(order.status);

  const move = (to: (typeof steps)[number]) =>
    transition.mutate(
      { to },
      {
        onSuccess: (updated) => toast.success(copy.moved(ar.orders.statuses[updated.status] ?? updated.status)),
        onError: (error) => toast.error(messageFor(error)),
      },
    );

  // A delivered order still takes collections (BR-C04) and returns (BR-R01); a cancelled one has no actions.
  if (steps.length === 0 && !cancellable && !collectable && !returnable && !discountable && !editable) {
    return canCancel(order.status) || order.status === "delivered" || preorder ? null : (
      <p className="text-muted-foreground text-sm">{copy.terminal}</p>
    );
  }

  return (
    <div className="flex flex-wrap gap-2" data-testid="order-actions">
      {steps.map((to) =>
        to === "delivered" ? (
          <DeliverDialog
            key={to}
            order={order}
            label={copy.moveTo[to] ?? to}
            variant={to === steps[0] ? "default" : "outline"}
            disabled={transition.isPending}
          />
        ) : (
          <Button
            key={to}
            variant={to === steps[0] ? "default" : "outline"}
            disabled={transition.isPending}
            onClick={() => move(to)}
          >
            {copy.moveTo[to]}
          </Button>
        ),
      )}
      {steps.includes("delivered") && owed && collects && (
        <DeliverDialog
          order={order}
          collect
          label={copy.deliverAndCollect}
          variant="secondary"
          hint={copy.deliverAndCollectHint(formatMoney(balance))}
          disabled={transition.isPending}
        />
      )}
      {editable && <EditLinesDialog order={order} />}
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
