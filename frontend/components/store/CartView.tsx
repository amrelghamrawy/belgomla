"use client";

import { CalendarClockIcon, ShoppingCartIcon, Trash2Icon } from "lucide-react";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { Cart, CartItem, CartLineKind, PreorderCartItem, RemovedCartItem } from "@/lib/api/types";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDay } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { useCart, useRemoveCartItem, useUpdateCartItem } from "@/lib/store/cart";
import { preorderBounds, quantityBounds } from "@/lib/store/quantity";

import { MovProgress } from "./MovProgress";
import { QuantityStepper } from "./QuantityStepper";
import { OfferBadge } from "./OfferBadge";
import { YourPriceBadge } from "./YourPriceBadge";

/** Quantity changes wait this long for the next tap before going to the server. */
export const CART_DEBOUNCE_MS = 400;

/**
 * `/cart` (BR-CU06): lines priced now (BR-PR04), lines removed since last time (BR-P04), the minimum order bar. The
 * pre-order lines (BR-O14) are a section of their own with an indicative total and their own confirmation.
 */
export function CartView() {
  const session = useRequiredCustomer();
  const active = session.data?.status === "active";
  const cart = useCart(active);
  const [removedNotice, setRemovedNotice] = useState<RemovedCartItem[]>([]);
  const [seenRemoved, setSeenRemoved] = useState<RemovedCartItem[] | undefined>(undefined);

  // The server reports removed lines once (BR-P04); keep the notice for the rest of this visit.
  const removed = cart.data?.removed_items;
  if (removed !== seenRemoved) {
    setSeenRemoved(removed);

    if (removed !== undefined && removed.length > 0) {
      setRemovedNotice(removed);
    }
  }

  if (!session.data) {
    return null;
  }

  if (session.data.status !== "active") {
    return (
      <main className="mx-auto w-full max-w-2xl flex-1 px-4 py-6">
        <Alert>
          <AlertDescription>{ar.errors[session.data.status]}</AlertDescription>
        </Alert>
      </main>
    );
  }

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 px-4 pt-6 pb-32 md:pb-6">
      <h1 className="text-xl font-bold">{ar.cart.title}</h1>

      {removedNotice.length > 0 && (
        <Alert>
          <AlertTitle>{ar.cart.removedTitle}</AlertTitle>
          <AlertDescription>
            <ul className="list-inside list-disc">
              {removedNotice.map((item) => (
                <li key={item.product_name}>{item.product_name}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}

      {cart.isError ? (
        <p role="alert" className="text-destructive">
          {messageFor(cart.error)}
        </p>
      ) : cart.data === undefined ? (
        <Skeleton className="h-40" />
      ) : cart.data.items.length === 0 && cart.data.preorders.items.length === 0 ? (
        <div className="flex flex-col items-center gap-3 py-12 text-center">
          <ShoppingCartIcon className="text-muted-foreground size-12" aria-hidden />
          <p className="font-bold">{ar.cart.empty}</p>
          <p className="text-muted-foreground text-sm">{ar.cart.emptyHint}</p>
          <Button asChild className="h-11">
            <Link href="/">{ar.cart.browse}</Link>
          </Button>
        </div>
      ) : cart.data.items.length === 0 ? (
        <PreorderSection cart={cart.data} />
      ) : (
        <>
          {cart.data.preorders.items.length > 0 && <PreorderSection cart={cart.data} />}
          <MovProgress cart={cart.data} />
          <ul className="flex flex-col gap-3">
            {cart.data.items.map((item) => (
              <CartLine key={item.sku_id} item={item} />
            ))}
          </ul>
          <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:static md:rounded-xl md:border">
            <div className="mx-auto flex max-w-2xl items-center gap-3">
              <div className="flex flex-1 flex-col">
                <span className="text-muted-foreground text-xs">{ar.cart.itemsTotal}</span>
                <span className="text-lg font-bold" data-testid="cart-total">
                  {formatMoney(cart.data.items_total)}
                </span>
              </div>
              <Button asChild size="lg" className="h-11 flex-1">
                <Link href="/checkout">{ar.cart.checkout}</Link>
              </Button>
            </div>
          </div>
        </>
      )}
    </main>
  );
}

/** BR-O14: the pre-order lines, their indicative total and the link to confirm them (no MOV, D88). */
function PreorderSection({ cart }: { cart: Cart }) {
  return (
    <section
      aria-labelledby="preorders-title"
      className="bg-brand-soft flex flex-col gap-3 rounded-2xl p-3"
      data-testid="preorders"
    >
      <div className="flex flex-col gap-1">
        <h2 id="preorders-title" className="font-bold">
          {ar.cart.preordersTitle}
        </h2>
        <p className="text-muted-foreground text-sm">{ar.cart.preordersHint}</p>
      </div>
      <ul className="flex flex-col gap-3">
        {cart.preorders.items.map((item) => (
          <CartLine key={item.sku_id} item={item} kind="preorder" />
        ))}
      </ul>
      <div className="flex items-center gap-3">
        <div className="flex flex-1 flex-col">
          <span className="text-muted-foreground text-xs">{ar.cart.preordersTotal}</span>
          <span className="text-lg font-bold" data-testid="preorders-total">
            {formatMoney(cart.preorders.items_total)}
          </span>
        </div>
        <Button asChild size="lg" variant="outline" className="border-primary text-primary h-11 flex-1 border-[1.5px]">
          <Link href="/checkout/preorder">{ar.cart.confirmPreorder}</Link>
        </Button>
      </div>
    </section>
  );
}

/** One line: name, unit price (tier noted), line total, quantity stepper and remove; a pre-order line has no stock cap. */
export function CartLine({ item, kind = "order" }: { item: CartItem | PreorderCartItem; kind?: CartLineKind }) {
  const update = useUpdateCartItem();
  const remove = useRemoveCartItem();
  const [quantity, setQuantity] = useState(item.quantity);
  const [serverQuantity, setServerQuantity] = useState(item.quantity);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const preorder = kind === "preorder";
  const bounds = preorder ? preorderBounds(item) : quantityBounds(item);
  const expectedAt = "expected_at" in item ? item.expected_at : null;

  if (serverQuantity !== item.quantity) {
    setServerQuantity(item.quantity);
    setQuantity(item.quantity);
  }

  useEffect(() => () => clearTimeout(timer.current), []);

  const change = (next: number) => {
    setQuantity(next);
    clearTimeout(timer.current);
    timer.current = setTimeout(
      () =>
        update.mutate(
          preorder ? { sku_id: item.sku_id, quantity: next, kind } : { sku_id: item.sku_id, quantity: next },
          {
            onError: (error) => {
              setQuantity(item.quantity);
              toast.error(messageFor(error));
            },
          },
        ),
      CART_DEBOUNCE_MS,
    );
  };

  return (
    <li className="flex flex-col gap-3 rounded-xl border p-3">
      <div className="flex items-start gap-2">
        <div className="flex flex-1 flex-col gap-1">
          <Link href={`/p/${encodeURIComponent(item.product_slug)}`} className="font-medium hover:underline">
            {item.product_name}
          </Link>
          <span className="text-muted-foreground text-sm">
            {ar.cart.unitPrice(formatMoney(item.unit_price))} / {item.unit}
            {item.tier_min_quantity !== null && ` · ${ar.cart.tierApplied(item.tier_min_quantity)}`}
          </span>
          <YourPriceBadge source={item.price_source} className="self-start" />
          <OfferBadge promotion={item.promotion} className="self-start" />
          {expectedAt !== null && (
            <span className="text-primary flex items-center gap-1 text-xs font-semibold">
              <CalendarClockIcon className="size-3.5" aria-hidden />
              {ar.cart.expectedAt(formatDay(expectedAt))}
            </span>
          )}
        </div>
        <Button
          variant="ghost"
          size="icon"
          className="text-destructive size-11"
          aria-label={`${ar.cart.remove}: ${item.product_name}`}
          disabled={remove.isPending}
          onClick={() =>
            remove.mutate(preorder ? { sku_id: item.sku_id, kind } : item.sku_id, {
              onError: (error) => toast.error(messageFor(error)),
            })
          }
        >
          <Trash2Icon />
        </Button>
      </div>
      <div className="flex items-center justify-between gap-2">
        <QuantityStepper
          id={preorder ? `quantity-preorder-${item.sku_id}` : `quantity-${item.sku_id}`}
          value={quantity}
          bounds={bounds}
          onChange={change}
        />
        <span className="font-bold">{formatMoney(item.line_total)}</span>
      </div>
    </li>
  );
}
