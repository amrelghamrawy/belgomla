"use client";

import Link from "next/link";
import type { ReactNode } from "react";

import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { OrderStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { useOrders } from "@/lib/dashboard/orders";
import { useLowStockReport, useOverviewReport } from "@/lib/dashboard/reports";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.overview;
const STATUSES: OrderStatus[] = ["pending", "confirmed", "preparing", "out_for_delivery", "delivered", "cancelled"];

function Stat({ label, value, href, tone }: { label: string; value: ReactNode; href: string; tone?: "warning" }) {
  return (
    <Link href={href} className="block">
      <Card className={cn("hover:border-primary h-full transition-colors", tone === "warning" && "border-amber-500")}>
        <CardHeader className="pb-1">
          <CardTitle className="text-muted-foreground text-sm font-normal">{label}</CardTitle>
        </CardHeader>
        <CardContent className="text-2xl font-bold">{value}</CardContent>
      </Card>
    </Link>
  );
}

/** The landing page (D42): today's numbers and what needs attention, from `reports/overview` (D5). */
export function Overview() {
  const report = useOverviewReport();

  return (
    <>
      <PageHeader title={copy.title} />
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={3} />}
      {report.data && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Stat label={copy.todayOrders} value={report.data.today.orders} href="/dashboard/orders" />
            <Stat label={copy.todaySales} value={formatMoney(report.data.today.total)} href="/dashboard/reports" />
            <Stat
              label={copy.stalePending}
              value={report.data.stale_pending}
              href="/dashboard/orders?stale=1"
              tone={report.data.stale_pending > 0 ? "warning" : undefined}
            />
            <Stat
              label={copy.outstanding}
              value={formatMoney(report.data.outstanding_total)}
              href="/dashboard/reports?tab=outstanding"
            />
            <Stat
              label={copy.lowStock}
              value={report.data.low_stock_count}
              href="/dashboard/inventory?low_stock=1"
              tone={report.data.low_stock_count > 0 ? "warning" : undefined}
            />
          </div>
          <Card>
            <CardHeader>
              <CardTitle>{copy.byStatus}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                {STATUSES.map((status) => (
                  <li key={status}>
                    <Link
                      href={`/dashboard/orders?status=${status}`}
                      className="hover:bg-muted flex flex-col rounded-md border p-3"
                      data-testid={`status-${status}`}
                    >
                      <span className="text-muted-foreground text-xs">{ar.orders.statuses[status]}</span>
                      <span className="text-lg font-bold">{report.data.orders_by_status[status]}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
          <div className="grid gap-4 lg:grid-cols-2">
            <StaleOrders />
            <LowStockList />
          </div>
        </>
      )}
    </>
  );
}

const STALE = { stale: "1", status: "pending", per_page: "5", sort: "placed_at" };

/** D10: pending orders older than the stale threshold, oldest first. */
function StaleOrders() {
  const orders = useOrders(STALE);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.staleOrders}</CardTitle>
        <CardAction>
          <Link href="/dashboard/orders?stale=1" className="text-primary text-sm hover:underline">
            {copy.viewAll}
          </Link>
        </CardAction>
      </CardHeader>
      <CardContent>
        {orders.data?.data.length === 0 && <p className="text-muted-foreground text-sm">{ar.dashboard.list.empty}</p>}
        <ul className="flex flex-col divide-y" data-testid="stale-orders">
          {orders.data?.data.map((order) => (
            <li key={order.number} className="flex items-center justify-between gap-2 py-2 text-sm">
              <Link href={`/dashboard/orders/${order.number}`} className="font-medium hover:underline">
                <bdi>#{order.number}</bdi>
              </Link>
              <span className="min-w-0 flex-1 truncate">{order.customer?.name ?? order.delivery.name}</span>
              <span className="text-muted-foreground text-xs whitespace-nowrap">{formatDateTime(order.placed_at)}</span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function LowStockList() {
  const lowStock = useLowStockReport(1, 5);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.lowStockList}</CardTitle>
        <CardAction>
          <Link href="/dashboard/inventory?low_stock=1" className="text-primary text-sm hover:underline">
            {copy.viewAll}
          </Link>
        </CardAction>
      </CardHeader>
      <CardContent>
        {lowStock.data?.data.length === 0 && <p className="text-muted-foreground text-sm">{ar.dashboard.list.empty}</p>}
        <ul className="flex flex-col divide-y" data-testid="low-stock">
          {lowStock.data?.data.map((sku) => (
            <li key={sku.sku_id} className="flex items-center justify-between gap-2 py-2 text-sm">
              <Link href={`/dashboard/inventory/${sku.sku_id}`} className="min-w-0 flex-1 truncate hover:underline">
                {sku.product_name}
              </Link>
              <span className="font-semibold whitespace-nowrap">
                {sku.available} / {sku.threshold}
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
