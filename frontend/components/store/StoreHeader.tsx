"use client";

import { ShoppingCartIcon, UserIcon } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Suspense } from "react";

import { useCustomerSession } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { formatPhone, whatsappLink } from "@/lib/format/phone";
import type { Category } from "@/lib/api/types";
import { useCart } from "@/lib/store/cart";
import { useCategories } from "@/lib/store/catalog";
import { cn } from "@/lib/utils";

import { SearchBox } from "./SearchBox";
import { StoreMark } from "./StoreMark";

/**
 * The store's own header (01 §7: the merchant's brand only, D67): a strip with the minimum order, delivery and
 * WhatsApp; the mark, search, account and cart (with the cart total); on wider screens a row of top categories.
 */
export function StoreHeader() {
  const { data: tenant } = useTenant();
  const { data: customer } = useCustomerSession();
  const active = customer?.status === "active";
  const { data: cart } = useCart(active);
  const { data: categories } = useCategories();
  const count = cart?.items.length ?? 0;
  const name = tenant?.name ?? ar.appName;

  return (
    <>
      {tenant !== undefined && (
        <div className="bg-highlight-soft text-foreground text-xs print:hidden">
          <div className="mx-auto flex min-h-8 w-full max-w-6xl [scrollbar-width:none] items-center gap-4 overflow-x-auto px-4 whitespace-nowrap">
            {tenant.mov.enabled && tenant.mov.amount !== null && (
              <span className="font-medium">{ar.store.minOrder(formatMoney(tenant.mov.amount))}</span>
            )}
            <span>{ar.store.delivery}</span>
            {tenant.whatsapp && (
              <a href={whatsappLink(tenant.whatsapp)} rel="noopener" className="hover:underline">
                {ar.store.whatsapp} <bdi className="font-semibold tabular-nums">{formatPhone(tenant.whatsapp)}</bdi>
              </a>
            )}
          </div>
        </div>
      )}
      <header className="bg-card/90 sticky top-0 z-40 border-b backdrop-blur print:hidden">
        <div className="mx-auto grid w-full max-w-6xl grid-cols-[1fr_auto] items-center gap-x-3 gap-y-2 px-4 py-2.5 md:grid-cols-[auto_1fr_auto] md:gap-6 md:py-3">
          <Link href="/" className="flex min-w-0 items-center gap-2.5" aria-label={ar.store.home}>
            <StoreMark name={name} logoUrl={tenant?.logo_url ?? null} />
            <span className="font-display text-primary truncate text-xl leading-tight font-bold">{name}</span>
          </Link>
          <SearchBox className="col-span-2 md:col-span-1 md:mx-auto md:w-full md:max-w-xl" />
          <nav className="col-start-2 row-start-1 flex items-center gap-1 md:col-start-3">
            <Link
              href={customer ? "/account" : "/account/login"}
              prefetch={false}
              aria-label={customer ? ar.store.account : ar.store.login}
              className="hover:bg-accent flex h-11 items-center gap-2 rounded-lg px-2.5"
            >
              <UserIcon className="size-5.5" aria-hidden />
              <span className="hidden text-start leading-tight md:block">
                <span className="block text-sm font-semibold">{customer ? ar.store.account : ar.store.login}</span>
                {customer && (
                  <span className="text-muted-foreground block max-w-28 truncate text-xs">{customer.name}</span>
                )}
              </span>
            </Link>
            <Link
              href="/cart"
              prefetch={false}
              aria-label={ar.store.cartCount(count)}
              className="hover:bg-accent flex h-11 items-center gap-2 rounded-lg px-2.5"
            >
              <span className="relative">
                <ShoppingCartIcon className="size-5.5" aria-hidden />
                {count > 0 && (
                  <span className="bg-highlight text-highlight-foreground absolute -end-2.5 -top-2 grid min-w-5 place-items-center rounded-full px-1 text-[0.6875rem] leading-5 font-bold tabular-nums">
                    {count}
                  </span>
                )}
              </span>
              <span className="hidden text-start leading-tight md:block">
                <span className="block text-sm font-semibold">{ar.store.cart}</span>
                {cart && (
                  <span className="text-muted-foreground block text-xs tabular-nums">
                    {formatMoney(cart.items_total)}
                  </span>
                )}
              </span>
            </Link>
          </nav>
        </div>
        {categories !== undefined && categories.length > 0 && (
          <nav aria-label={ar.store.categories} className="hidden border-t md:block">
            <div className="mx-auto flex h-11 w-full max-w-6xl items-center gap-1 px-4">
              <Suspense fallback={<CategoryLinks categories={categories} current={null} />}>
                <CurrentCategoryLinks categories={categories} />
              </Suspense>
              <Link href="/price-list" className="text-primary ms-auto px-3 py-1 text-sm font-semibold">
                {ar.store.priceList}
              </Link>
            </div>
          </nav>
        )}
      </header>
    </>
  );
}

/** The category links with the current one marked; reading the path needs a Suspense boundary (Next 16). */
function CurrentCategoryLinks({ categories }: { categories: readonly Category[] }) {
  return <CategoryLinks categories={categories} current={decodeURIComponent(usePathname())} />;
}

function CategoryLinks({ categories, current }: { categories: readonly Category[]; current: string | null }) {
  return categories.map((category) => (
    <Link
      key={category.id}
      href={`/c/${encodeURIComponent(category.slug)}`}
      className={cn(
        "text-muted-foreground hover:bg-brand-soft hover:text-primary rounded-full px-3 py-1 text-sm font-medium",
        current === `/c/${category.slug}` && "bg-brand-soft text-primary",
      )}
    >
      {category.name}
    </Link>
  ));
}
