import Link from "next/link";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { ar } from "@/lib/copy/ar";
import { belowReservedDetails } from "@/lib/dashboard/inventory";

const copy = ar.dashboard.inventory;

/**
 * BR-INV08: the refusal to go below the reserved quantity. The backend's Arabic message names the orders;
 * each one is also a link to its order page so the merchant can cancel or deliver it.
 */
export function BelowReservedError({ error }: { error: unknown }) {
  const details = belowReservedDetails(error);

  if (details === null) {
    return null;
  }

  return (
    <Alert variant="destructive" data-testid="below-reserved">
      <AlertDescription className="flex flex-col gap-2">
        <span>{details.error.message}</span>
        {details.orders.length > 0 && (
          <span className="flex flex-wrap items-center gap-2">
            {copy.holdingOrders}
            {details.orders.map((order) => (
              <Link key={order.number} href={`/dashboard/orders/${order.number}`} className="font-medium underline">
                <bdi>{copy.orderLink(order.number)}</bdi>
              </Link>
            ))}
          </span>
        )}
      </AlertDescription>
    </Alert>
  );
}
