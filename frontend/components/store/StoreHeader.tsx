"use client";

import { ShoppingCartIcon, UserIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import { Button } from "@/components/ui/button";
import { useCustomerSession } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { useCart } from "@/lib/store/cart";

import { SearchBox } from "./SearchBox";

/** The store's own header (01 §7: the merchant's brand only): logo, search, account and cart. */
export function StoreHeader() {
  const { data: tenant } = useTenant();
  const { data: customer } = useCustomerSession();
  const active = customer?.status === "active";
  const { data: cart } = useCart(active);
  const count = cart?.items.length ?? 0;

  return (
    <header className="bg-background/95 sticky top-0 z-40 border-b backdrop-blur print:hidden">
      <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 md:flex-nowrap">
        <Link href="/" className="flex min-w-0 flex-1 items-center gap-2 md:flex-none" aria-label={ar.store.home}>
          {tenant?.logo_url && (
            <Image src={tenant.logo_url} alt="" width={36} height={36} className="size-9 rounded-md object-contain" />
          )}
          <span className="text-primary truncate text-lg font-bold">{tenant?.name ?? ar.appName}</span>
        </Link>
        <SearchBox className="order-last md:order-none md:mx-4 md:max-w-xl" />
        <nav className="flex items-center gap-1">
          <Button asChild variant="ghost" size="icon" className="size-11">
            <Link
              href={customer ? "/account" : "/account/login"}
              prefetch={false}
              aria-label={customer ? ar.store.account : ar.store.login}
            >
              <UserIcon />
            </Link>
          </Button>
          <Button asChild variant="ghost" size="icon" className="relative size-11">
            <Link href="/cart" prefetch={false} aria-label={ar.store.cartCount(count)}>
              <ShoppingCartIcon />
              {count > 0 && (
                <span className="bg-primary text-primary-foreground absolute end-1 top-1 grid min-w-5 place-items-center rounded-full px-1 text-xs leading-5">
                  {count}
                </span>
              )}
            </Link>
          </Button>
        </nav>
      </div>
    </header>
  );
}
