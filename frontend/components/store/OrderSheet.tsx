"use client";

import Link from "next/link";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useCustomerSession } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useCart } from "@/lib/store/cart";
import { movProgress } from "@/lib/store/quantity";
import { cn } from "@/lib/utils";

const SHOWN_LINES = 3;

/**
 * The hero's order sheet (D67), set like a page of an order book: the newest cart lines with the server's line
 * totals, the progress to the minimum order (BR-MOV02) and the total. A guest is asked to log in; a customer who is
 * not active yet sees their account status.
 */
export function OrderSheet() {
  const { data: tenant } = useTenant();
  const { data: customer } = useCustomerSession();
  const active = customer?.status === "active";
  const { data: cart } = useCart(active);

  return (
    <aside
      aria-labelledby="order-sheet-title"
      className="bg-card text-card-foreground shadow-card flex flex-col gap-3 self-start rounded-2xl p-4 sm:p-5"
    >
      <div className="flex items-baseline justify-between gap-2">
        <h2 id="order-sheet-title" className="font-display text-lg font-semibold">
          {ar.store.sheet.title}
        </h2>
        {cart && cart.items.length > 0 && (
          <span className="text-muted-foreground text-xs">{ar.store.sheet.items(cart.items.length)}</span>
        )}
      </div>

      {customer === undefined || (active && cart === undefined) ? (
        <Skeleton className="h-32" />
      ) : customer === null ? (
        <>
          <p className="text-muted-foreground text-sm leading-relaxed">{ar.store.sheet.guest}</p>
          {tenant?.mov.enabled && tenant.mov.amount !== null && (
            <p className="text-sm font-medium">{ar.store.minOrder(formatMoney(tenant.mov.amount))}</p>
          )}
          <div className="grid grid-cols-2 gap-2">
            <Button asChild className="h-11">
              <Link href="/account/login">{ar.store.login}</Link>
            </Button>
            <Button asChild variant="outline" className="h-11">
              <Link href="/account/register">{ar.store.sheet.register}</Link>
            </Button>
          </div>
        </>
      ) : !active ? (
        <p className="text-muted-foreground text-sm">{ar.store.sheet.status(customer.status_label)}</p>
      ) : (
        cart && <ActiveSheet cart={cart} />
      )}
    </aside>
  );
}

function ActiveSheet({ cart }: { cart: NonNullable<ReturnType<typeof useCart>["data"]> }) {
  const progress = movProgress(cart);
  const lines = cart.items.slice(-SHOWN_LINES).reverse();
  const hidden = cart.items.length - lines.length;

  return (
    <>
      {lines.length === 0 ? (
        <p className="text-muted-foreground text-sm leading-relaxed">{ar.store.sheet.empty}</p>
      ) : (
        <ul className="flex flex-col text-sm">
          {lines.map((line) => (
            <li key={line.sku_id} className="flex h-8 items-baseline gap-2">
              <span className="min-w-0 truncate">{line.product_name}</span>
              <span
                aria-hidden
                className="border-muted-foreground/40 min-w-3 flex-1 -translate-y-1 border-b border-dotted"
              />
              <span className="text-muted-foreground shrink-0 tabular-nums">
                × {line.quantity} {line.unit}
              </span>
              <span className="shrink-0 font-semibold tabular-nums">{formatMoney(line.line_total)}</span>
            </li>
          ))}
          {hidden > 0 && <li className="text-muted-foreground h-8 leading-8">{ar.store.sheet.more(hidden)}</li>}
        </ul>
      )}
      {progress !== null && (
        <div className="flex flex-col gap-1.5" data-testid="sheet-mov">
          <div className="bg-brand-soft h-2 overflow-hidden rounded-full" role="presentation">
            <div
              className={cn(
                "h-full rounded-full transition-[width]",
                progress.satisfied ? "bg-highlight" : "bg-primary",
              )}
              style={{ width: `${progress.percent}%` }}
            />
          </div>
          <p className="text-muted-foreground text-xs">
            {progress.satisfied ? ar.cart.movReached : ar.cart.movRemaining(formatMoney(progress.remaining))}
          </p>
        </div>
      )}
      <div className="border-foreground flex items-baseline justify-between border-t-2 pt-2.5">
        <span className="text-sm">{ar.store.sheet.total}</span>
        <strong className="text-2xl tabular-nums" data-testid="sheet-total">
          {formatMoney(cart.items_total)}
        </strong>
      </div>
      {lines.length > 0 && (
        <Button asChild className="h-11">
          <Link href="/cart">{ar.store.sheet.review}</Link>
        </Button>
      )}
    </>
  );
}
