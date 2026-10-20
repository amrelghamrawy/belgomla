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
import { formatDay } from "@/lib/format/date";
import { useAddToCart } from "@/lib/store/cart";
import { canOrder, preorderBounds, quantityBounds } from "@/lib/store/quantity";

import { QuantityStepper } from "./QuantityStepper";

/**
 * The product page's buy box, a sticky bar on phones (rules/03 §3). Starts at the MOQ; disabled for coming-soon
 * (D21) and out-of-stock products. A Coming Soon product that takes pre-orders (BR-O14, D88) is booked instead
 * ("احجز الآن"): no stock, up to what remains of the merchant's cap, with the expected day and the note that the final
 * price is set when it arrives. A guest is sent to log in first (BR-CU06: no guest carts).
 */
export function AddToCartBar({ product }: { product: StoreProduct }) {
  const router = useRouter();
  const { data: customer } = useCustomerSession();
  const add = useAddToCart();
  const sku = product.sku;
  const preorder = sku?.coming_soon === true && product.preorder?.enabled === true ? product.preorder : null;
  const bounds =
    sku === null
      ? { min: 1, max: 0 }
      : preorder !== null
        ? preorderBounds(sku, preorder.remaining)
        : quantityBounds(sku);
  const [quantity, setQuantity] = useState(bounds.min);
  const orderable = sku !== null && (sku.coming_soon !== true || preorder !== null) && canOrder(bounds);
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
      { sku_id: sku.id, quantity, ...(preorder !== null ? { kind: "preorder" as const } : {}) },
      {
        onSuccess: () =>
          toast.success(preorder !== null ? ar.product.preorderAdded : ar.product.added, {
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
  const label = (() => {
    if (preorder !== null) {
      return !orderable ? ar.catalog.preorderFull : add.isPending ? ar.product.adding : ar.catalog.preorder;
    }

    if (sku?.coming_soon === true) {
      return ar.catalog.comingSoon;
    }

    return !orderable ? ar.catalog.outOfStock : add.isPending ? ar.product.adding : ar.product.addToCart;
  })();

  return (
    <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0">
      <div className="mx-auto flex max-w-6xl flex-col gap-2">
        {preorder !== null && (
          <p className="text-primary text-xs font-semibold" data-testid="preorder-terms">
            {[
              preorder.expected_at === null ? null : ar.catalog.expectedAt(formatDay(preorder.expected_at)),
              ar.catalog.finalPriceNote,
              preorder.remaining === null ? null : ar.catalog.preorderRemaining(preorder.remaining, unit),
            ]
              .filter((part) => part !== null)
              .join(" · ")}
          </p>
        )}
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
            {label}
          </Button>
        </div>
        {preorder !== null && <p className="text-muted-foreground text-xs">{ar.product.preorderHint}</p>}
      </div>
    </div>
  );
}
