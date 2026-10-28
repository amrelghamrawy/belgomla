"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { StatusPage } from "@/components/shared/StatusPage";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import type { OrderDetail, OrderHistoryEntry } from "@/lib/api/types";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { changedLineIds, liveItems, variantOf } from "@/lib/store/order-lines";
import { useCancelOrder, useOrder } from "@/lib/store/orders";
import { useRouteParam } from "@/lib/store/route";

import { AddressText } from "./AddressList";

const REASON_MAX = 300;

/**
 * `/account/orders/[number]`: status history, items, returns (BR-R05), payment status, delivery; cancel while pending
 * (BR-O02). A pre-order (BR-O14) shows where it stands, how much of each line became an order and links to those
 * orders; an order converted from a pre-order links back to it.
 */
export function OrderDetailView() {
  const number = Number(useRouteParam("number"));
  const session = useRequiredCustomer();
  const order = useOrder(number);

  if (!session.data) {
    return null;
  }

  if (isApiError(order.error, "not_found") || !Number.isInteger(number)) {
    return (
      <StatusPage
        title={ar.pages.notFoundTitle}
        action={
          <Button asChild className="h-11">
            <Link href="/account/orders">{ar.orders.title}</Link>
          </Button>
        }
      />
    );
  }

  if (order.error) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(order.error)} />;
  }

  if (order.data === undefined) {
    return (
      <main className="mx-auto w-full max-w-2xl flex-1 px-4 py-6" aria-busy>
        <Skeleton className="h-64" />
      </main>
    );
  }

  return <OrderDetailBody order={order.data} />;
}

function OrderDetailBody({ order }: { order: OrderDetail }) {
  const changed = changedLineIds(order);
  const preorder = order.kind === "preorder";

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 px-4 py-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-bold">
            {preorder ? ar.orders.preorderNumber(order.number) : ar.orders.number(order.number)}
          </h1>
          <p className="text-muted-foreground text-sm">
            {ar.orders.placedAt}: {formatDateTime(order.placed_at)}
          </p>
        </div>
        <Badge variant={order.status === "cancelled" ? "destructive" : "default"} data-testid="order-status">
          {order.preorder_state === null ? order.status_label : ar.orders.preorderStates[order.preorder_state]}
        </Badge>
      </div>

      {preorder && order.preorder_state === "open" && (
        <Alert>
          <AlertDescription>{ar.orders.preorderPriceNote}</AlertDescription>
        </Alert>
      )}

      {order.conversions.length > 0 && (
        <p className="flex flex-wrap items-center gap-2 text-sm" data-testid="conversions">
          <span className="font-medium">{ar.orders.convertedTo}:</span>
          {order.conversions.map((conversion) => (
            <Link
              key={conversion.number}
              href={`/account/orders/${conversion.number}`}
              className="text-primary underline"
            >
              {ar.orders.number(conversion.number)}
            </Link>
          ))}
        </p>
      )}

      {order.converted_from !== null && (
        <Link
          href={`/account/orders/${order.converted_from}`}
          className="text-primary text-sm underline"
          data-testid="converted-from"
        >
          {ar.orders.convertedFrom(order.converted_from)}
        </Link>
      )}

      {order.status === "cancelled" && order.cancel_reason && (
        <Alert>
          <AlertDescription>{ar.orders.cancelledReason(order.cancel_reason)}</AlertDescription>
        </Alert>
      )}

      <section aria-labelledby="order-timeline" className="flex flex-col gap-2">
        <h2 id="order-timeline" className="font-bold">
          {ar.orders.timeline}
        </h2>
        <StatusTimeline history={order.history} />
      </section>

      <section aria-labelledby="order-items" className="flex flex-col gap-2">
        <h2 id="order-items" className="font-bold">
          {ar.orders.items} ({ar.orders.itemsCount(liveItems(order).length)})
        </h2>
        <ul className="flex flex-col divide-y rounded-xl border">
          {order.items.map((item) => (
            <li
              key={item.id}
              data-testid={`order-line-${item.id}`}
              className={
                item.removed_at === null
                  ? "flex items-start justify-between gap-3 p-3 text-sm"
                  : "text-muted-foreground flex items-start justify-between gap-3 p-3 text-sm line-through"
              }
            >
              <div className="flex flex-col gap-0.5">
                <span className="font-medium">
                  {item.product_name}
                  {item.removed_at !== null ? (
                    <Badge variant="outline" className="ms-2 text-xs">
                      {ar.orders.lineRemoved}
                    </Badge>
                  ) : (
                    changed.has(item.id) && (
                      <Badge variant="secondary" className="ms-2 text-xs">
                        {ar.orders.lineChanged}
                      </Badge>
                    )
                  )}
                </span>
                {variantOf(item) !== null && (
                  <span className="text-muted-foreground text-xs" data-testid={`variant-${item.id}`}>
                    {variantOf(item)}
                  </span>
                )}
                {item.converted_quantity > 0 && (
                  <span className="text-primary text-xs" data-testid={`converted-${item.id}`}>
                    {ar.orders.convertedOf(item.converted_quantity, item.quantity, item.unit)}
                  </span>
                )}
                {item.delivered_quantity !== null && item.delivered_quantity < item.quantity && (
                  <span className="text-destructive text-xs" data-testid={`delivered-${item.id}`}>
                    {ar.orders.deliveredOf(item.delivered_quantity, item.quantity, item.unit)}
                  </span>
                )}
                <span className="text-muted-foreground">
                  {ar.orders.quantityOf(item.quantity, item.unit)} ×{" "}
                  {item.manual_discount !== null && (
                    <s aria-label={ar.orders.listPrice} data-testid="list-price">
                      {formatMoney(item.list_unit_price)}
                    </s>
                  )}{" "}
                  {formatMoney(item.unit_price)}
                </span>
              </div>
              <span className="font-medium whitespace-nowrap">{formatMoney(item.line_total)}</span>
            </li>
          ))}
        </ul>
        {order.discount !== null && (
          <>
            <p className="text-muted-foreground flex justify-between text-sm">
              <span>{ar.orders.itemsTotal}</span>
              <span>{formatMoney(order.items_total)}</span>
            </p>
            <p className="text-muted-foreground flex justify-between text-sm" data-testid="order-discount">
              <span>{ar.orders.orderDiscount}</span>
              <span>−{formatMoney(order.discount.amount)}</span>
            </p>
          </>
        )}
        <p className="flex justify-between font-bold">
          <span>{ar.orders.total}</span>
          <span>{formatMoney(order.order_total)}</span>
        </p>
        {order.returned_total !== "0.00" && (
          <>
            <p className="text-muted-foreground flex justify-between text-sm">
              <span>{ar.orders.returnedTotal}</span>
              <span>−{formatMoney(order.returned_total)}</span>
            </p>
            <p className="flex justify-between font-bold" data-testid="order-net-total">
              <span>{ar.orders.netTotal}</span>
              <span>{formatMoney(order.net_total)}</span>
            </p>
          </>
        )}
      </section>

      {order.returns.length > 0 && (
        <section aria-labelledby="order-returns" className="flex flex-col gap-2">
          <h2 id="order-returns" className="font-bold">
            {ar.orders.returns}
          </h2>
          <ul className="flex flex-col divide-y rounded-xl border">
            {order.returns.map((orderReturn) => (
              <li key={orderReturn.number} className="flex flex-col gap-1 p-3 text-sm">
                <span className="flex justify-between font-medium">
                  <span>
                    {ar.orders.returnNumber(orderReturn.number)} · {formatDateTime(orderReturn.returned_at)}
                  </span>
                  <span>{formatMoney(orderReturn.total)}</span>
                </span>
                {orderReturn.items.map((item, index) => (
                  <span key={`${item.sku_code}-${index}`} className="text-muted-foreground">
                    {item.product_name} · {ar.orders.quantityOf(item.quantity, item.unit)} ×{" "}
                    {formatMoney(item.unit_price)}
                  </span>
                ))}
                {orderReturn.refund_amount !== "0.00" && (
                  <span>{ar.orders.refunded(formatMoney(orderReturn.refund_amount))}</span>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {!preorder && (
        <section aria-labelledby="order-payment" className="flex flex-col gap-1 text-sm">
          <h2 id="order-payment" className="font-bold">
            {ar.orders.collection}
          </h2>
          <p>
            {ar.orders.collectionStatuses[order.collection_status]}
            {order.collection_status !== "unpaid" && ` · ${ar.orders.collected(formatMoney(order.collected_amount))}`}
          </p>
        </section>
      )}

      <section aria-labelledby="order-delivery" className="flex flex-col gap-1">
        <h2 id="order-delivery" className="font-bold">
          {ar.orders.delivery}
        </h2>
        <p className="text-sm">
          {order.delivery.name} · <bdi>{formatPhone(order.delivery.phone)}</bdi>
        </p>
        <AddressText address={order.delivery.address} />
      </section>

      {order.customer_note && (
        <section className="flex flex-col gap-1 text-sm">
          <h2 className="font-bold">{ar.orders.note}</h2>
          <p className="whitespace-pre-line">{order.customer_note}</p>
        </section>
      )}

      {order.status === "pending" && order.preorder_state !== "converted" && (
        <CancelOrderDialog number={order.number} preorder={preorder} />
      )}
    </main>
  );
}

/** Every status the order went through, oldest first, with who moved it. */
export function StatusTimeline({ history }: { history: readonly OrderHistoryEntry[] }) {
  return (
    <ol className="flex flex-col gap-3 border-s-2 ps-4">
      {history.map((entry) => (
        <li key={`${entry.to}-${entry.at}`} className="relative flex flex-col">
          <span className="bg-primary absolute -start-[1.4rem] top-1.5 size-3 rounded-full" aria-hidden />
          <span className="font-medium">{entry.from === null ? ar.orders.placed : ar.orders.statuses[entry.to]}</span>
          <span className="text-muted-foreground text-xs">
            {formatDateTime(entry.at)} · {ar.orders.by[entry.by]}
          </span>
        </li>
      ))}
    </ol>
  );
}

/** BR-O02: a pending order can be cancelled by the customer, with a reason; so can an open pre-order (BR-O14). */
export function CancelOrderDialog({ number, preorder = false }: { number: number; preorder?: boolean }) {
  const cancel = useCancelOrder(number);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (reason.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    setError(null);
    cancel.mutate(reason.trim(), {
      onSuccess: () => {
        setOpen(false);
        toast.success(ar.orders.cancelled);
      },
      onError: (failure) => setError(messageFor(failure)),
    });
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button variant="outline" className="text-destructive h-11" onClick={() => setOpen(true)}>
        {preorder ? ar.orders.cancelPreorder : ar.orders.cancel}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{preorder ? ar.orders.cancelPreorder : ar.orders.cancelTitle}</DialogTitle>
          <DialogDescription>{preorder ? ar.orders.cancelPreorderBody : ar.orders.cancelBody}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <FieldLabel htmlFor="cancel-reason">{ar.orders.cancelReason}</FieldLabel>
          <Textarea
            id="cancel-reason"
            value={reason}
            maxLength={REASON_MAX}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={error !== null}
            rows={3}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" className="h-11" onClick={() => setOpen(false)}>
              {ar.cancel}
            </Button>
            <Button type="submit" variant="destructive" className="h-11" disabled={cancel.isPending}>
              {cancel.isPending ? ar.orders.cancelling : ar.orders.confirmCancel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
