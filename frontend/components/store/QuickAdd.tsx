"use client";

import { MinusIcon, PlusIcon, Trash2Icon } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { loginPaths } from "@/lib/api/client";
import { isApiError } from "@/lib/api/errors";
import type { StoreProduct } from "@/lib/api/types";
import { useCustomerSession } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useAddToCart, useCart, useRemoveCartItem, useUpdateCartItem } from "@/lib/store/cart";
import { canOrder, quantityBounds } from "@/lib/store/quantity";

/**
 * Add-to-cart on a product card (D67): one tap adds the MOQ; once the product is in the cart the button becomes
 * − quantity + on the server's cart line (BR-MOQ01: below the MOQ the line is removed). A guest goes to log in
 * first (BR-CU06). Nothing is shown when prices are hidden: the price box already links to login.
 */
export function QuickAdd({ product }: { product: StoreProduct }) {
  const router = useRouter();
  const { data: customer } = useCustomerSession();
  const { data: cart } = useCart(customer?.status === "active");
  const add = useAddToCart();
  const update = useUpdateCartItem();
  const remove = useRemoveCartItem();
  const sku = product.sku;

  if (sku === null || product.prices_hidden === true) {
    return null;
  }

  if (sku.coming_soon === true) {
    return (
      <Button variant="outline" className="h-11 w-full border-dashed" disabled>
        {ar.catalog.comingSoon}
      </Button>
    );
  }

  const bounds = quantityBounds(sku);

  if (!canOrder(bounds)) {
    return (
      <Button variant="outline" className="h-11 w-full" disabled>
        {ar.catalog.outOfStock}
      </Button>
    );
  }

  // Read when tapped, not with usePathname(): cards render in prerendered shells, where that hook needs Suspense.
  const login = () =>
    `${loginPaths.store}?next=${encodeURIComponent(window.location.pathname + window.location.search)}`;
  const busy = add.isPending || update.isPending || remove.isPending;
  const onError = (error: Error) => {
    if (isApiError(error, "prices_hidden")) {
      router.push(login());
    } else {
      toast.error(messageFor(error));
    }
  };
  const line = cart?.items.find((item) => item.sku_id === sku.id);
  const unit = sku.unit ?? "";

  if (line === undefined) {
    return (
      <Button
        variant="outline"
        className="border-primary text-primary hover:bg-primary hover:text-primary-foreground h-11 w-full border-[1.5px] font-bold"
        aria-label={ar.catalog.quickAddLabel(product.name)}
        disabled={busy}
        onClick={() => {
          if (customer === null) {
            router.push(login());
            return;
          }

          add.mutate({ sku_id: sku.id, quantity: bounds.min }, { onError });
        }}
      >
        <PlusIcon aria-hidden />
        {bounds.min > 1 ? ar.catalog.quickAddMin(bounds.min, unit) : ar.catalog.quickAdd}
      </Button>
    );
  }

  const atMinimum = line.quantity <= bounds.min;

  return (
    <div
      role="group"
      aria-label={ar.catalog.inCart(line.quantity, line.unit)}
      className="bg-primary text-primary-foreground grid h-11 grid-cols-[2.75rem_1fr_2.75rem] overflow-hidden rounded-lg"
    >
      <button
        type="button"
        className="grid place-items-center hover:bg-black/10 disabled:opacity-50"
        aria-label={atMinimum ? ar.cart.remove : ar.product.decrease}
        disabled={busy}
        onClick={() =>
          atMinimum
            ? remove.mutate(sku.id, { onError })
            : update.mutate({ sku_id: sku.id, quantity: line.quantity - 1 }, { onError })
        }
      >
        {atMinimum ? <Trash2Icon className="size-4" aria-hidden /> : <MinusIcon className="size-4" aria-hidden />}
      </button>
      <output className="flex flex-col items-center justify-center leading-tight" aria-live="polite">
        <span className="text-sm font-bold tabular-nums">
          {line.quantity} {line.unit}
        </span>
        <span className="text-[0.6875rem] tabular-nums opacity-85">{formatMoney(line.line_total)}</span>
      </output>
      <button
        type="button"
        className="grid place-items-center hover:bg-black/10 disabled:opacity-50"
        aria-label={ar.product.increase}
        disabled={busy || line.quantity >= bounds.max}
        onClick={() => update.mutate({ sku_id: sku.id, quantity: line.quantity + 1 }, { onError })}
      >
        <PlusIcon className="size-4" aria-hidden />
      </button>
    </div>
  );
}
