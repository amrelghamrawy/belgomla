"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useApplyDiscount } from "@/lib/dashboard/orders";
import {
  type DiscountDraft,
  discountBody,
  discountTotals,
  draftOf,
  hasDiscount,
  NO_DISCOUNT,
} from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

import { DiscountInput } from "./DiscountInput";

const copy = ar.dashboard.orders.discount;

/** BR-PR08, D85: the "خصم" button of a pending or confirmed order and its dialog. */
export function DiscountDialog({ order }: { order: DashboardOrderDetail }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline">{copy.button}</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{copy.title}</DialogTitle>
          <DialogDescription>{copy.hint}</DialogDescription>
        </DialogHeader>
        {open && <DiscountForm order={order} onDone={() => setOpen(false)} />}
      </DialogContent>
    </Dialog>
  );
}

/**
 * Per line an EGP / % discount off the list price, and one on the whole order with its reason, with the totals the
 * server will compute (lib/dashboard/price-math.ts). The server stays the authority (422 discount_too_large,
 * 403 discount_over_limit for a sales user, 409 below_collected).
 */
function DiscountForm({ order, onDone }: { order: DashboardOrderDetail; onDone: () => void }) {
  const apply = useApplyDiscount(order.number);
  const [lines, setLines] = useState<Record<number, DiscountDraft>>(() =>
    Object.fromEntries(order.items.map((item) => [item.id, draftOf(item.manual_discount)])),
  );
  const [orderDraft, setOrderDraft] = useState<DiscountDraft>(() => draftOf(order.discount));
  const [reason, setReason] = useState(order.discount?.reason ?? "");
  const [error, setError] = useState<string | null>(null);
  const totals = discountTotals(
    order.items.map((item) => ({
      list_unit_price: item.list_unit_price,
      quantity: item.quantity,
      discount: lines[item.id],
    })),
    orderDraft,
  );

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (totals.order_total === null) {
      setError(totals.items_total === null ? copy.invalid : copy.orderInvalid);
      return;
    }

    const orderBody = discountBody(orderDraft);

    if (orderBody !== null && reason.trim() === "") {
      setError(copy.reasonRequired);
      return;
    }

    setError(null);
    apply.mutate(
      {
        items: order.items.flatMap((item) => {
          const body = discountBody(lines[item.id]);

          return body === null ? [] : [{ order_item_id: item.id, ...body }];
        }),
        order: orderBody === null ? null : { ...orderBody, reason: reason.trim() },
      },
      {
        onSuccess: () => {
          toast.success(copy.saved);
          onDone();
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-3">
      <ul className="flex flex-col divide-y rounded-md border" data-testid="discount-lines">
        {order.items.map((item, index) => {
          const priced = totals.lines[index];
          const draft = lines[item.id] ?? NO_DISCOUNT;

          return (
            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
              <span className="flex min-w-0 flex-col">
                <span className="font-medium">{item.product_name}</span>
                <span className="text-muted-foreground text-xs">
                  {ar.orders.quantityOf(item.quantity, item.unit)} × {formatMoney(item.list_unit_price)}
                  {hasDiscount(draft) && priced ? ` → ${formatMoney(priced.unit_price)}` : ""}
                </span>
              </span>
              <DiscountInput
                id={`discount-line-${item.id}`}
                label={item.product_name}
                draft={draft}
                invalid={priced === null}
                onChange={(next) => setLines((current) => ({ ...current, [item.id]: next }))}
              />
            </li>
          );
        })}
      </ul>
      <Field>
        <FieldLabel htmlFor="discount-order">{copy.order}</FieldLabel>
        <DiscountInput
          id="discount-order"
          label={copy.order}
          draft={orderDraft}
          invalid={totals.items_total !== null && totals.discount_amount === null}
          onChange={setOrderDraft}
        />
      </Field>
      {hasDiscount(orderDraft) && (
        <Field>
          <FieldLabel htmlFor="discount-reason">{copy.reason}</FieldLabel>
          <Textarea
            id="discount-reason"
            rows={2}
            maxLength={300}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
      )}
      <dl className="grid grid-cols-2 gap-1 text-sm sm:max-w-xs" data-testid="discount-totals">
        <dt className="text-muted-foreground">{ar.dashboard.orders.itemsTotal}</dt>
        <dd>{totals.items_total === null ? "—" : formatMoney(totals.items_total)}</dd>
        <dt className="text-muted-foreground">{ar.dashboard.orders.discountTotal}</dt>
        <dd>{totals.discount_amount === null ? "—" : `−${formatMoney(totals.discount_amount)}`}</dd>
        <dt className="text-muted-foreground">{copy.totals}</dt>
        <dd className="font-bold" data-testid="discount-order-total">
          {totals.order_total === null ? "—" : formatMoney(totals.order_total)}
        </dd>
      </dl>
      {error !== null && <FieldError role="alert">{error}</FieldError>}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>
          {ar.cancel}
        </Button>
        <Button type="submit" disabled={apply.isPending}>
          {apply.isPending ? ar.saving : copy.save}
        </Button>
      </DialogFooter>
    </form>
  );
}
