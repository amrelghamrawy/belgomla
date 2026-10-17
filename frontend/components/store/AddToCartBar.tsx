"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { loginPaths } from "@/lib/api/client";
import { isApiError } from "@/lib/api/errors";
import type { StoreProduct } from "@/lib/api/types";
import { useCustomerSession } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { useAddToCart } from "@/lib/store/cart";
import { canOrder, quantityBounds } from "@/lib/store/quantity";

import { QuantityStepper } from "./QuantityStepper";

/**
 * The product page's buy box, a sticky bar on phones (rules/03 §3). Starts at the MOQ; disabled for coming-soon
 * (D21) and out-of-stock products. A guest is sent to log in first (BR-CU06: no guest carts).
 */
export function AddToCartBar({ product }: { product: StoreProduct }) {
  const router = useRouter();
  const { data: customer } = useCustomerSession();
  const add = useAddToCart();
  const sku = product.sku;
  const bounds = sku === null ? { min: 1, max: 0 } : quantityBounds(sku);
  const [quantity, setQuantity] = useState(bounds.min);
  const orderable = sku !== null && sku.coming_soon !== true && canOrder(bounds);
  const here = `/p/${encodeURIComponent(product.slug)}`;
  const login = `${loginPaths.store}?next=${encodeURIComponent(here)}`;

  const submit = () => {
    if (sku === null) {
      return;
    }

    if (customer === null) {
      router.push(login);
      return;
    }

    add.mutate(
      { sku_id: sku.id, quantity },
      {
        onSuccess: () =>
          toast.success(ar.product.added, {
            action: { label: ar.product.viewCart, onClick: () => router.push("/cart") },
          }),
        onError: (error) => {
          if (isApiError(error, "prices_hidden")) {
            router.push(login);
          } else {
            toast.error(messageFor(error));
          }
        },
      },
    );
  };

  const unit = sku?.unit ?? "";

  return (
    <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0">
      <div className="mx-auto flex max-w-6xl flex-col gap-2">
        {orderable && (
          <p className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
            <span>{ar.product.minQuantity(bounds.min, unit)}</span>
            {sku.conversion_factor > 1 && (
              <span className="text-foreground font-semibold tabular-nums">
                {ar.product.totalPieces(quantity * sku.conversion_factor)}
              </span>
            )}
          </p>
        )}
        <div className="flex items-center gap-3">
          {orderable && (
            <QuantityStepper value={quantity} bounds={bounds} onChange={setQuantity} disabled={add.isPending} />
          )}
          <Button className="h-11 flex-1" size="lg" disabled={!orderable || add.isPending} onClick={submit}>
            {sku?.coming_soon === true
              ? ar.catalog.comingSoon
              : !orderable
                ? ar.catalog.outOfStock
                : add.isPending
                  ? ar.product.adding
                  : ar.product.addToCart}
          </Button>
        </div>
      </div>
    </div>
  );
}
