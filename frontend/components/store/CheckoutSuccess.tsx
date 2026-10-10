"use client";

import { CircleCheckIcon } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";

import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";

/** After checkout: the order number (BR-O09) and where to follow it. */
export function CheckoutSuccess() {
  const number = Number(useSearchParams().get("order"));
  const valid = Number.isInteger(number) && number > 0;

  return (
    <main className="mx-auto flex w-full max-w-md flex-1 flex-col items-center gap-4 px-4 py-12 text-center">
      <CircleCheckIcon className="text-primary size-16" aria-hidden />
      <h1 className="text-2xl font-bold">{ar.checkout.successTitle}</h1>
      {valid && (
        <p className="text-lg">
          {ar.checkout.orderNumber}:{" "}
          <bdi className="font-bold" data-testid="order-number">
            {number}
          </bdi>
        </p>
      )}
      <p className="text-muted-foreground">{ar.checkout.successBody}</p>
      <div className="flex w-full flex-col gap-2">
        {valid && (
          <Button asChild size="lg" className="h-11">
            <Link href={`/account/orders/${number}`}>{ar.checkout.viewOrder}</Link>
          </Button>
        )}
        <Button asChild variant="outline" size="lg" className="h-11">
          <Link href="/">{ar.checkout.continueShopping}</Link>
        </Button>
      </div>
    </main>
  );
}
