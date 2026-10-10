"use client";

import Link from "next/link";
import type { ReactNode } from "react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { OrderStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { useOverviewReport } from "@/lib/dashboard/reports";
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
        </>
      )}
    </>
  );
}
