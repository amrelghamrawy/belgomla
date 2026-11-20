"use client";

import { ChevronRightIcon, PackageIcon } from "lucide-react";
import Link from "next/link";
import { useCallback, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { OrderKind, OrderStatus } from "@/lib/api/types";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { useOrdersInfinite } from "@/lib/store/orders";

import { LoadMore } from "./LoadMore";

const STATUSES = Object.keys(ar.orders.statuses) as OrderStatus[];

const KINDS: OrderKind[] = ["order", "preorder"];

/**
 * `/account/orders`: the customer's orders, newest first, filterable by status (D6: status lives here), and their
 * pre-orders on a tab of their own (BR-O14) with where each stands.
 */
export function OrdersList() {
  const session = useRequiredCustomer();
  const [kind, setKind] = useState<OrderKind>("order");
  const [status, setStatus] = useState<OrderStatus | undefined>(undefined);
  const orders = useOrdersInfinite(kind === "order" ? status : undefined, kind);
  const loadMore = useCallback(() => void orders.fetchNextPage(), [orders]);

  if (!session.data) {
    return null;
  }

  const rows = orders.data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 px-4 py-6">
      <h1 className="text-xl font-bold">{ar.orders.title}</h1>
      <div className="bg-muted grid grid-cols-2 gap-1 rounded-xl p-1" role="tablist" aria-label={ar.orders.title}>
        {KINDS.map((value) => (
          <Button
            key={value}
            role="tab"
            variant={kind === value ? "default" : "ghost"}
            className="h-11"
            aria-selected={kind === value}
            onClick={() => setKind(value)}
          >
            {ar.orders.kinds[value]}
          </Button>
        ))}
      </div>
      {kind === "order" && (
        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="group" aria-label={ar.orders.title}>
          {[undefined, ...STATUSES].map((value) => (
            <Button
              key={value ?? "all"}
              variant={status === value ? "default" : "outline"}
              size="sm"
              className="h-9 shrink-0 rounded-full"
              aria-pressed={status === value}
              onClick={() => setStatus(value)}
            >
              {value === undefined ? ar.orders.all : ar.orders.statuses[value]}
            </Button>
          ))}
        </div>
      )}

      {orders.isError ? (
        <p role="alert" className="text-destructive">
          {messageFor(orders.error)}
        </p>
      ) : orders.data === undefined ? (
        <Skeleton className="h-40" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 py-12 text-center">
          <PackageIcon className="text-muted-foreground size-10" aria-hidden />
          <p className="text-muted-foreground">{kind === "preorder" ? ar.orders.preorderEmpty : ar.orders.empty}</p>
        </div>
      ) : (
        <>
          <ul className="flex flex-col gap-3">
            {rows.map((order) => (
              <li key={order.number}>
                <Link
                  href={`/account/orders/${order.number}`}
                  className="hover:bg-muted/50 flex items-center gap-3 rounded-xl border p-3"
                >
                  <div className="flex flex-1 flex-col gap-1">
                    <span className="font-bold">
                      {order.kind === "preorder"
                        ? ar.orders.preorderNumber(order.number)
                        : ar.orders.number(order.number)}
                    </span>
                    <span className="text-muted-foreground text-sm">{formatDate(order.placed_at)}</span>
                    <span className="text-sm">{formatMoney(order.order_total)}</span>
                  </div>
                  <Badge variant={order.status === "cancelled" ? "destructive" : "secondary"}>
                    {order.preorder_state === null
                      ? order.status_label
                      : ar.orders.preorderStates[order.preorder_state]}
                  </Badge>
                  <ChevronRightIcon className="text-muted-foreground size-4 rtl:rotate-180" aria-hidden />
                </Link>
              </li>
            ))}
          </ul>
          <LoadMore hasMore={orders.hasNextPage} loading={orders.isFetchingNextPage} onLoad={loadMore} />
        </>
      )}
    </main>
  );
}
