"use client";

import { CircleCheckIcon } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";

import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";

/** After checkout: the order number (BR-O09) and where to follow it; after a pre-order (BR-O14), what happens next. */
export function CheckoutSuccess() {
  const params = useSearchParams();
  const number = Number(params.get("order"));
  const preorder = params.get("preorder") === "1";
  const valid = Number.isInteger(number) && number > 0;

  return (
    <main className="mx-auto flex w-full max-w-md flex-1 flex-col items-center gap-4 px-4 py-12 text-center">
      <CircleCheckIcon className="text-primary size-16" aria-hidden />
      <h1 className="text-2xl font-bold">{preorder ? ar.checkout.preorderSuccessTitle : ar.checkout.successTitle}</h1>
      {valid && (
        <p className="text-lg">
          {preorder ? ar.checkout.preorderNumber : ar.checkout.orderNumber}:{" "}
          <bdi className="font-bold" data-testid="order-number">
            {number}
          </bdi>
        </p>
      )}
      <p className="text-muted-foreground">{preorder ? ar.checkout.preorderSuccessBody : ar.checkout.successBody}</p>
      <div className="flex w-full flex-col gap-2">
        {valid && (
          <Button asChild size="lg" className="h-11">
            <Link href={`/account/orders/${number}`}>
              {preorder ? ar.checkout.viewPreorder : ar.checkout.viewOrder}
            </Link>
          </Button>
        )}
        <Button asChild variant="outline" size="lg" className="h-11">
          <Link href="/">{ar.checkout.continueShopping}</Link>
        </Button>
      </div>
    </main>
  );
}
