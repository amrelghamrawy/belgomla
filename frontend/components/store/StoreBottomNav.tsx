"use client";

import { HomeIcon, LayoutGridIcon, ReceiptTextIcon, ShoppingCartIcon, UserIcon, type LucideIcon } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";

import { useCustomerSession } from "@/lib/auth/session";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useCart } from "@/lib/store/cart";
import { movProgress } from "@/lib/store/quantity";
import { cn } from "@/lib/utils";

/** Pages with their own sticky bottom action (rules/03 §3) do not get the bar. */
const OWN_BOTTOM_BAR = ["/p/", "/cart", "/checkout"];

/**
 * The phone's bottom navigation (D67): home, categories, price list, cart and account. With items in the cart a
 * strip above it shows the cart total and the progress to the minimum order (BR-MOV02) and opens the cart.
 */
export function StoreBottomNav() {
  const pathname = usePathname();
  const { data: customer } = useCustomerSession();
  const { data: cart } = useCart(customer?.status === "active");

  if (OWN_BOTTOM_BAR.some((prefix) => pathname.startsWith(prefix))) {
    return null;
  }

  const count = cart?.items.length ?? 0;
  const progress = cart && count > 0 ? movProgress(cart) : null;
  const items: { href: string; label: string; icon: LucideIcon; current: boolean; badge?: number }[] = [
    { href: "/", label: ar.store.nav.home, icon: HomeIcon, current: pathname === "/" },
    { href: "/#categories", label: ar.store.nav.categories, icon: LayoutGridIcon, current: pathname.startsWith("/c/") },
    { href: "/price-list", label: ar.store.nav.prices, icon: ReceiptTextIcon, current: pathname === "/price-list" },
    { href: "/cart", label: ar.store.nav.cart, icon: ShoppingCartIcon, current: false, badge: count },
    {
      href: customer ? "/account" : "/account/login",
      label: ar.store.nav.account,
      icon: UserIcon,
      current: pathname.startsWith("/account"),
    },
  ];

  return (
    <>
      <div aria-hidden className={cn("md:hidden print:hidden", count > 0 ? "h-28" : "h-16")} />
      <div className="fixed inset-x-0 bottom-0 z-30 md:hidden print:hidden">
        {cart && count > 0 && (
          <Link
            href="/cart"
            prefetch={false}
            className="bg-foreground text-background flex items-center gap-3 px-4 py-2"
            data-testid="bottom-cart-strip"
          >
            <span className="flex min-w-0 flex-1 flex-col gap-1 text-xs">
              <span>
                <b className="text-sm tabular-nums">{formatMoney(cart.items_total)}</b>
                {progress !== null && (
                  <>
                    {" · "}
                    {progress.satisfied ? ar.cart.movReached : ar.cart.movRemaining(formatMoney(progress.remaining))}
                  </>
                )}
              </span>
              {progress !== null && (
                <span className="bg-background/20 block h-1.5 overflow-hidden rounded-full">
                  <span
                    className={cn("block h-full rounded-full", progress.satisfied ? "bg-highlight" : "bg-background")}
                    style={{ width: `${progress.percent}%` }}
                  />
                </span>
              )}
            </span>
            <span className="bg-highlight text-highlight-foreground rounded-lg px-3 py-1.5 text-sm font-bold">
              {ar.store.cart}
            </span>
          </Link>
        )}
        <nav
          aria-label={ar.store.nav.label}
          className="bg-card grid grid-cols-5 border-t pb-[env(safe-area-inset-bottom)]"
        >
          {items.map(({ href, label, icon: Icon, current, badge }) => (
            <Link
              key={label}
              href={href}
              prefetch={false}
              aria-current={current ? "page" : undefined}
              className={cn(
                "relative flex h-14 flex-col items-center justify-center gap-0.5 text-[0.6875rem]",
                current ? "text-primary font-semibold" : "text-muted-foreground",
              )}
            >
              <Icon className="size-5.5" aria-hidden />
              {label}
              {badge !== undefined && badge > 0 && (
                <span className="bg-highlight text-highlight-foreground absolute start-[calc(50%+0.25rem)] top-1.5 grid min-w-4.5 place-items-center rounded-full px-1 text-[0.625rem] leading-4.5 font-bold tabular-nums">
                  {badge}
                </span>
              )}
            </Link>
          ))}
        </nav>
      </div>
    </>
  );
}
