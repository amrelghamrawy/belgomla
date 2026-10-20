"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { clampDelivered, deliveredBody, deliveredItemsTotal, liveItems } from "@/lib/dashboard/edit-order";
import { useTransitionOrder } from "@/lib/dashboard/orders";
import { formatMoney } from "@/lib/format/money";

import { Hint } from "../common/Hint";

const copy = ar.dashboard.orders.deliver;

type Props = {
  order: DashboardOrderDetail;
  /** The "deliver and collect in full" shortcut (BR-C04): collects the total of what was delivered. */
  collect?: boolean;
  label: string;
  variant?: "default" | "outline" | "secondary";
  hint?: string;
  disabled?: boolean;
};

/**
 * BR-O13, D86: moving an order to delivered goes through a confirmation that lists what each line delivered, the
 * ordered quantity by default, collapsed under «تسليم جزئي؟». A short line releases the rest of its stock and the
 * order is charged for what went out; there is no back-order.
 */
export function DeliverDialog({ order, collect = false, label, variant = "default", hint, disabled }: Props) {
  const [open, setOpen] = useState(false);
  const trigger = (
    <Button variant={variant} disabled={disabled} onClick={() => setOpen(true)}>
      {label}
    </Button>
  );

  return (
    <>
      {hint === undefined ? trigger : <Hint text={hint}>{trigger}</Hint>}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{copy.title}</DialogTitle>
            <DialogDescription>{copy.body}</DialogDescription>
          </DialogHeader>
          {open && <DeliverForm order={order} collect={collect} onDone={() => setOpen(false)} />}
        </DialogContent>
      </Dialog>
    </>
  );
}

function DeliverForm({
  order,
  collect,
  onDone,
}: {
  order: DashboardOrderDetail;
  collect: boolean;
  onDone: () => void;
}) {
  const transition = useTransitionOrder(order.number);
  const items = liveItems(order);
  const [partial, setPartial] = useState(false);
  const [delivered, setDelivered] = useState<Record<number, number>>(() =>
    Object.fromEntries(items.map((item) => [item.id, item.quantity])),
  );
  const [error, setError] = useState<string | null>(null);
  const nothing = partial && items.every((item) => (delivered[item.id] ?? item.quantity) === 0);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    transition.mutate(
      {
        to: "delivered",
        collect_full_amount: collect || undefined,
        delivered_quantities: partial ? deliveredBody(order, delivered) : undefined,
      },
      {
        onSuccess: () => {
          toast.success(
            collect
              ? ar.dashboard.orders.deliveredAndCollected
              : ar.dashboard.orders.moved(ar.orders.statuses.delivered),
          );
          onDone();
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <div className="flex items-start gap-2">
        <Checkbox id="partial-delivery" checked={partial} onCheckedChange={(checked) => setPartial(checked === true)} />
        <div className="flex flex-col gap-0.5">
          <Label htmlFor="partial-delivery">{copy.partial}</Label>
          <span className="text-muted-foreground text-xs">{copy.partialHint}</span>
        </div>
      </div>
      {partial && (
        <ul className="flex flex-col divide-y rounded-md border" data-testid="delivered-lines">
          {items.map((item) => (
            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
              <span className="flex min-w-0 flex-col">
                <span className="font-medium">{item.product_name}</span>
                <span className="text-muted-foreground text-xs">{copy.ordered(item.quantity, item.unit)}</span>
              </span>
              <Input
                type="number"
                inputMode="numeric"
                min={0}
                max={item.quantity}
                step={1}
                className="w-24"
                aria-label={copy.deliveredOf(item.product_name)}
                value={delivered[item.id] ?? item.quantity}
                onChange={(event) =>
                  setDelivered((current) => ({
                    ...current,
                    [item.id]: clampDelivered(Number(event.target.value), item.quantity),
                  }))
                }
              />
            </li>
          ))}
        </ul>
      )}
      {partial && (
        <p className="text-sm font-bold" data-testid="delivered-total">
          {copy.total(formatMoney(deliveredItemsTotal(order, delivered)))}
        </p>
      )}
      {nothing && (
        <Alert variant="destructive">
          <AlertDescription>{copy.nothing}</AlertDescription>
        </Alert>
      )}
      {error !== null && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <DialogFooter>
        <Button type="submit" disabled={nothing || transition.isPending}>
          {collect ? copy.confirmCollect : copy.confirm}
        </Button>
      </DialogFooter>
    </form>
  );
}
