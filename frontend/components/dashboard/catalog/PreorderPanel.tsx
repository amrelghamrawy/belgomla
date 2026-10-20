"use client";

import { CalendarClockIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import type { DashboardProduct, PreorderConversion } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDay } from "@/lib/format/date";
import { useCan } from "@/lib/dashboard/permissions";
import { useConvertPreorders, usePreorderDemand } from "@/lib/dashboard/preorders";

const copy = ar.dashboard.preorders;

/**
 * BR-O14, D88 on the product page: what customers pre-ordered and did not receive yet (quantity, customers, pre-orders,
 * what remains under the cap) for the merchant's purchase planning, and, once the product is active and stocked,
 * «تحويل الحجوزات إلى طلبات» with the summary of what was converted. Shown for products that take, or took,
 * pre-orders.
 */
export function PreorderPanel({ product }: { product: DashboardProduct }) {
  const relevant = product.preorder.enabled || product.status === "coming_soon";
  const demand = usePreorderDemand(product.id, relevant);
  const convert = useConvertPreorders(product.id);
  const can = useCan();
  const [result, setResult] = useState<PreorderConversion | null>(null);

  if (!relevant) {
    return null;
  }

  const unit = product.sku?.selling_unit?.name ?? "";
  const data = demand.data;

  return (
    <section
      aria-labelledby="preorder-panel-title"
      className="bg-muted/40 mt-4 flex flex-col gap-3 rounded-xl border p-4"
      data-testid="preorder-panel"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="preorder-panel-title" className="font-bold">
          {copy.title}
        </h2>
        {product.preorder.expected_at !== null && (
          <span className="text-muted-foreground flex items-center gap-1 text-sm">
            <CalendarClockIcon className="size-4" aria-hidden />
            {formatDay(product.preorder.expected_at)}
          </span>
        )}
      </div>

      {data === undefined ? null : data.preorders === 0 ? (
        <p className="text-muted-foreground text-sm">{copy.none}</p>
      ) : (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm" data-testid="preorder-demand">
          <li className="font-bold">{copy.quantity(data.quantity, unit)}</li>
          <li>{copy.customers(data.customers)}</li>
          <li>{copy.count(data.preorders)}</li>
          {data.remaining !== null && <li>{copy.remaining(data.remaining, unit)}</li>}
        </ul>
      )}

      {can("orders.manage") && data !== undefined && data.preorders > 0 && (
        <div className="flex flex-col gap-2">
          {product.status === "active" ? (
            <>
              <p className="text-muted-foreground text-xs">{copy.convertHint}</p>
              <Button
                type="button"
                className="justify-self-start md:w-fit"
                disabled={convert.isPending}
                onClick={() =>
                  convert.mutate(undefined, {
                    onSuccess: setResult,
                    onError: (failure) => toast.error(messageFor(failure)),
                  })
                }
              >
                {convert.isPending ? copy.converting : copy.convert}
              </Button>
            </>
          ) : (
            <p className="text-muted-foreground text-sm">{copy.activateFirst}</p>
          )}
        </div>
      )}

      {result !== null && (
        <Alert data-testid="conversion-summary">
          <AlertTitle>{copy.summaryTitle}</AlertTitle>
          <AlertDescription>
            <ul className="flex flex-col gap-1">
              {result.converted_orders.length === 0 && <li>{copy.nothingConverted}</li>}
              {result.converted_orders.map((order) => (
                <li key={order.number}>
                  <Link href={`/dashboard/orders/${order.number}`} className="underline">
                    {copy.convertedOrder(order.number, order.preorder_number, order.quantity)}
                  </Link>
                </li>
              ))}
              {result.partially > 0 && <li>{copy.partially(result.partially)}</li>}
              {result.untouched > 0 && result.converted_orders.length > 0 && (
                <li>{copy.untouched(result.untouched)}</li>
              )}
            </ul>
          </AlertDescription>
        </Alert>
      )}

      <Link href={`/dashboard/orders?kind=preorder`} className="text-primary text-sm underline">
        {copy.viewList}
      </Link>
    </section>
  );
}
