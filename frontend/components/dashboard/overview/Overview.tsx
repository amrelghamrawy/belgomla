"use client";

import {
  BanknoteIcon,
  ClockAlertIcon,
  HandCoinsIcon,
  type LucideIcon,
  PackageMinusIcon,
  ShoppingBagIcon,
  TriangleAlertIcon,
  WalletIcon,
} from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { OrderStatus } from "@/lib/api/types";
import { useDashboardSession } from "@/lib/auth/session";
import { ar } from "@/lib/copy/ar";
import { useOrders } from "@/lib/dashboard/orders";
import { useCan } from "@/lib/dashboard/permissions";
import { useLowStockReport, useOutstandingReport, useOverviewReport, useSalesReport } from "@/lib/dashboard/reports";
import { formatDate, formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { CollectionStatusBadge } from "../orders/OrderBadges";
import { SalesChart } from "../reports/SalesChart";
import { BarList } from "./BarList";

const copy = ar.dashboard.overview;
const STATUSES: OrderStatus[] = ["pending", "confirmed", "preparing", "out_for_delivery", "delivered", "cancelled"];

function Stat({
  label,
  value,
  href,
  icon: Icon,
  tone,
  hint,
}: {
  label: string;
  value: ReactNode;
  href: string;
  icon: LucideIcon;
  tone?: "warning";
  hint?: string;
}) {
  const warning = tone === "warning";

  // D104: the reference design's statistic card: a round icon tile, then the label over the figure. A figure that
  // needs action turns its tile amber and says so in words (WCAG 1.4.1), not only by colour.
  return (
    <Link
      href={href}
      data-tone={tone}
      className="group bg-card shadow-panel focus-visible:ring-ring flex h-full items-center gap-4 rounded-[20px] px-5 py-4 transition-transform outline-none hover:-translate-y-0.5 focus-visible:ring-2"
    >
      <span
        aria-hidden
        className={cn(
          "flex size-14 shrink-0 items-center justify-center rounded-full",
          warning ? "bg-warn-soft text-warn" : "bg-muted text-primary",
        )}
      >
        <Icon className="size-6" />
      </span>
      <span className="flex min-w-0 flex-col gap-1">
        <span className="text-muted-foreground truncate text-sm">{label}</span>
        <span className="font-heading text-foreground text-[1.4rem] leading-tight font-bold whitespace-nowrap tabular-nums">
          {value}
        </span>
        {(hint !== undefined || warning) && (
          <span className="flex flex-wrap items-center gap-x-2 text-xs">
            {warning && (
              <span className="text-warn flex items-center gap-1 font-bold">
                <TriangleAlertIcon aria-hidden className="size-3.5" />
                {copy.needsAction}
              </span>
            )}
            {hint !== undefined && <span className="text-muted-foreground">{hint}</span>}
          </span>
        )}
      </span>
    </Link>
  );
}

/**
 * The landing page (D42): today's numbers and what needs attention, from `reports/overview` (D5), with the
 * last 30 days of sales, orders per status and the largest debts as charts. A role without `reports.view` (D82)
 * gets a greeting instead.
 */
export function Overview() {
  const allowed = useCan();

  return allowed("reports.view") ? <ReportsOverview /> : <Welcome />;
}

function Welcome() {
  const me = useDashboardSession().data;

  return (
    <PageHeader
      title={ar.dashboard.welcome(me?.user.name ?? "")}
      description={<span data-testid="welcome">{ar.dashboard.welcomeHint}</span>}
    />
  );
}

function ReportsOverview() {
  const allowed = useCan();

  return (
    <>
      <PageHeader
        title={copy.title}
        description={formatDate(new Date().toISOString())}
        actions={
          allowed("orders.view") ? (
            <Button asChild>
              <Link href="/dashboard/orders?status=pending">{copy.reviewPending}</Link>
            </Button>
          ) : undefined
        }
      />
      <OverviewBody />
    </>
  );
}

/** The overview's cards and charts without the page title: the landing page, and the reports page's first tab. */
export function OverviewBody() {
  const report = useOverviewReport();

  return (
    <>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={3} />}
      {report.data && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Stat
              label={copy.todayOrders}
              value={report.data.today.orders}
              href="/dashboard/orders"
              icon={ShoppingBagIcon}
            />
            <Stat
              label={copy.todaySales}
              icon={BanknoteIcon}
              value={formatMoney(report.data.today.total)}
              href="/dashboard/reports?tab=sales"
            />
            <Stat
              label={copy.stalePending}
              icon={ClockAlertIcon}
              value={report.data.stale_pending}
              href="/dashboard/orders?stale=1"
              tone={report.data.stale_pending > 0 ? "warning" : undefined}
            />
            <Stat
              label={copy.outstanding}
              icon={WalletIcon}
              value={formatMoney(report.data.outstanding_total)}
              href="/dashboard/reports?tab=collections"
            />
            <Stat
              label={copy.overdue}
              icon={HandCoinsIcon}
              value={formatMoney(report.data.overdue_total)}
              hint={copy.overdueCustomers(report.data.overdue_customers)}
              href="/dashboard/reports?tab=collections"
              tone={report.data.overdue_customers > 0 ? "warning" : undefined}
            />
            <Stat
              label={copy.lowStock}
              icon={PackageMinusIcon}
              value={report.data.low_stock_count}
              href="/dashboard/inventory?low_stock=1"
              tone={report.data.low_stock_count > 0 ? "warning" : undefined}
            />
          </div>
          {/* D104: orders are the first daily job, so the latest of them lead, beside who owes the most. */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <LatestOrders />
            <TopDebtors />
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <SalesTrend />
            <Card>
              <CardHeader>
                <CardTitle>{copy.byStatus}</CardTitle>
              </CardHeader>
              <CardContent>
                <BarList
                  label={copy.byStatus}
                  testId="status"
                  items={STATUSES.map((status) => ({
                    key: status,
                    label: ar.orders.statuses[status],
                    value: report.data.orders_by_status[status],
                    display: String(report.data.orders_by_status[status]),
                    href: `/dashboard/orders?status=${status}`,
                    muted: status === "cancelled",
                  }))}
                />
              </CardContent>
            </Card>
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <StaleOrders />
            <LowStockList />
          </div>
        </>
      )}
    </>
  );
}

/** Delivered sales of the last 30 days (the report's default range): the total as the headline, then the columns. */
function SalesTrend() {
  const sales = useSalesReport({});

  return (
    <Card className="lg:col-span-2">
      <CardHeader>
        <CardTitle>{copy.salesTrend}</CardTitle>
        <CardAction>
          <Link href="/dashboard/reports?tab=sales" className="text-primary text-sm hover:underline">
            {copy.viewReport}
          </Link>
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {sales.isError && <ErrorBlock error={sales.error} onRetry={() => void sales.refetch()} />}
        {sales.data && (
          <>
            <p className="flex flex-wrap items-baseline gap-x-3" data-testid="sales-trend-total">
              <span className="text-2xl font-bold">{formatMoney(sales.data.totals.net)}</span>
              <span className="text-muted-foreground text-sm">{copy.salesTrendOrders(sales.data.totals.orders)}</span>
            </p>
            {sales.data.totals.orders === 0 ? (
              <p className="text-muted-foreground text-sm">{copy.noSales}</p>
            ) : (
              <SalesChart report={sales.data} className="h-56" />
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** The five customers who owe the most on delivered orders (D59), as bars on one scale. */
function TopDebtors() {
  const outstanding = useOutstandingReport(1);
  const rows = outstanding.data?.data.slice(0, 5) ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.topDebtors}</CardTitle>
        <CardAction>
          <Link href="/dashboard/reports?tab=collections" className="text-primary text-sm hover:underline">
            {copy.viewAll}
          </Link>
        </CardAction>
      </CardHeader>
      <CardContent>
        {outstanding.data !== undefined && rows.length === 0 && (
          <p className="text-muted-foreground text-sm">{copy.noDebtors}</p>
        )}
        <BarList
          label={copy.topDebtors}
          testId="debtors"
          items={rows.map((row) => ({
            key: String(row.customer.id),
            label: row.customer.name,
            value: Number(row.outstanding),
            display: formatMoney(row.outstanding),
            href: `/dashboard/customers/${row.customer.id}?tab=statement`,
          }))}
        />
      </CardContent>
    </Card>
  );
}

const LATEST = { per_page: "5" };

/** D104: the five newest orders as the book's latest slips: red serial, customer, collection copy and total. */
function LatestOrders() {
  const orders = useOrders(LATEST);

  return (
    <Card className="lg:col-span-2">
      <CardHeader>
        <CardTitle>{copy.latestOrders}</CardTitle>
        <CardAction>
          <Link href="/dashboard/orders" className="text-primary text-sm hover:underline">
            {copy.viewAll}
          </Link>
        </CardAction>
      </CardHeader>
      <CardContent>
        {orders.isError && <ErrorBlock error={orders.error} onRetry={() => void orders.refetch()} />}
        {orders.data?.data.length === 0 && <p className="text-muted-foreground text-sm">{ar.dashboard.list.empty}</p>}
        <ul className="flex flex-col divide-y" data-testid="latest-orders">
          {orders.data?.data.map((order) => (
            <li key={order.number} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
              <Link
                href={`/dashboard/orders/${order.number}`}
                className="text-primary font-bold tabular-nums underline-offset-4 hover:underline"
              >
                <bdi>#{order.number}</bdi>
              </Link>
              <span className="min-w-0 flex-1 truncate">{order.customer?.name ?? order.delivery.name}</span>
              <CollectionStatusBadge status={order.collection_status} />
              <span className="text-primary min-w-24 text-end font-semibold whitespace-nowrap tabular-nums">
                {formatMoney(order.order_total)}
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
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
              <Link
                href={`/dashboard/orders/${order.number}`}
                className="text-primary font-bold tabular-nums underline-offset-4 hover:underline"
              >
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
              <span className="text-primary font-semibold whitespace-nowrap tabular-nums">
                {sku.available} / {sku.threshold}
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
