"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type { SalesGrouping } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { activeFilters, pageOf } from "@/lib/dashboard/list-query";
import { useLowStockReport, useOutstandingReport, useSalesReport } from "@/lib/dashboard/reports";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";

import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { SalesChart } from "./SalesChart";

const copy = ar.dashboard.reports;
const GROUPS: SalesGrouping[] = ["day", "week", "month"];

/** Basic reports (D5): sales over time, outstanding per customer, low stock. Export is V2. */
export function ReportsPage() {
  const [query, setQuery] = useListQuery(["tab", "from", "to", "group"]);
  const tab = query.tab ?? "sales";

  return (
    <>
      <PageHeader title={copy.title} description={copy.exportLater} />
      <Tabs dir="rtl" value={tab} onValueChange={(value) => setQuery({ tab: value === "sales" ? null : value })}>
        <TabsList>
          <TabsTrigger value="sales">{copy.tabs.sales}</TabsTrigger>
          <TabsTrigger value="outstanding">{copy.tabs.outstanding}</TabsTrigger>
          <TabsTrigger value="low-stock">{copy.tabs.lowStock}</TabsTrigger>
        </TabsList>
        <TabsContent value="sales" className="pt-4">
          <SalesSection query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="outstanding" className="pt-4">
          <OutstandingSection page={pageOf(query)} onPage={(page) => setQuery({ page })} />
        </TabsContent>
        <TabsContent value="low-stock" className="pt-4">
          <LowStockSection page={pageOf(query)} onPage={(page) => setQuery({ page })} />
        </TabsContent>
      </Tabs>
    </>
  );
}

type SectionProps = {
  query: Record<string, string>;
  setQuery: (patch: Record<string, string | number | null>) => void;
};

export function SalesSection({ query, setQuery }: SectionProps) {
  const report = useSalesReport({ from: query.from, to: query.to, group: query.group });
  const [table, setTable] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <FilterPanel
        active={activeFilters(query, ["from", "to", "group"])}
        leading={
          <Button variant="outline" onClick={() => setTable(!table)} aria-pressed={table}>
            {table ? copy.showChart : copy.showTable}
          </Button>
        }
      >
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input
            type="date"
            value={query.from ?? report.data?.from ?? ""}
            onChange={(event) => setQuery({ from: event.target.value })}
          />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input
            type="date"
            value={query.to ?? report.data?.to ?? ""}
            onChange={(event) => setQuery({ to: event.target.value })}
          />
        </label>
        <NativeSelect
          aria-label={copy.group}
          value={query.group ?? "day"}
          onChange={(event) => setQuery({ group: event.target.value === "day" ? null : event.target.value })}
        >
          {GROUPS.map((group) => (
            <option key={group} value={group}>
              {copy.groups[group]}
            </option>
          ))}
        </NativeSelect>
      </FilterPanel>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {report.data && (
        <>
          <div className="grid grid-cols-2 gap-3 sm:max-w-xl sm:grid-cols-3">
            <div className="rounded-md border p-3">
              <p className="text-muted-foreground text-xs">{copy.totalSales}</p>
              <p className="text-xl font-semibold" data-testid="sales-total">
                {formatMoney(report.data.totals.net)}
              </p>
            </div>
            <div className="rounded-md border p-3">
              <p className="text-muted-foreground text-xs">{copy.totalReturns}</p>
              <p className="text-xl font-semibold" data-testid="returns-total">
                {formatMoney(report.data.totals.returns)}
              </p>
            </div>
            <div className="rounded-md border p-3">
              <p className="text-muted-foreground text-xs">{copy.totalOrders}</p>
              <p className="text-xl font-semibold">{report.data.totals.orders}</p>
            </div>
          </div>
          <Card>
            <CardHeader>
              <CardTitle>{copy.salesTitle}</CardTitle>
              <p className="text-muted-foreground text-xs">{copy.salesHint}</p>
            </CardHeader>
            <CardContent>
              {report.data.buckets.length === 0 ||
              (report.data.totals.orders === 0 && report.data.totals.returns === "0.00") ? (
                <EmptyBlock>{copy.noSales}</EmptyBlock>
              ) : table ? (
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.period}</TableHead>
                        <TableHead>{copy.orders}</TableHead>
                        <TableHead>{copy.gross}</TableHead>
                        <TableHead>{copy.returns}</TableHead>
                        <TableHead>{copy.net}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {report.data.buckets.map((bucket) => (
                        <TableRow key={bucket.period_start}>
                          <TableCell>{formatDate(bucket.period_start)}</TableCell>
                          <TableCell>{bucket.orders}</TableCell>
                          <TableCell>{formatMoney(bucket.gross)}</TableCell>
                          <TableCell>{formatMoney(bucket.returns)}</TableCell>
                          <TableCell>{formatMoney(bucket.net)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <SalesChart report={report.data} />
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

/** BR-C06: the aging buckets of the outstanding report, oldest last. */
const AGING = ["current", "0_30", "31_60", "61_90", "over_90"] as const;

export function OutstandingSection({ page, onPage }: { page: number; onPage: (page: number) => void }) {
  const report = useOutstandingReport(page);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">
        {copy.outstandingHint} {copy.agingHint}
      </p>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock />}
      {report.data?.data.length === 0 && <EmptyBlock>{copy.noOutstanding}</EmptyBlock>}
      {report.data !== undefined && report.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.customer}</TableHead>
                <TableHead>{copy.ordersCount}</TableHead>
                <TableHead>{copy.total}</TableHead>
                <TableHead>{copy.collected}</TableHead>
                <TableHead>{copy.outstanding}</TableHead>
                {AGING.map((bucket) => (
                  <TableHead key={bucket}>{copy.aging[bucket]}</TableHead>
                ))}
                <TableHead>{copy.overdue}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {report.data.data.map((row) => (
                <TableRow key={row.customer.id}>
                  <TableCell>
                    <Link
                      href={`/dashboard/customers/${row.customer.id}?tab=statement`}
                      className="font-medium hover:underline"
                    >
                      {row.customer.name}
                    </Link>{" "}
                    <bdi className="text-muted-foreground text-xs">{formatPhone(row.customer.phone)}</bdi>
                  </TableCell>
                  <TableCell>{row.orders}</TableCell>
                  <TableCell className="whitespace-nowrap">{formatMoney(row.total)}</TableCell>
                  <TableCell className="whitespace-nowrap">{formatMoney(row.collected)}</TableCell>
                  <TableCell className="font-semibold whitespace-nowrap">{formatMoney(row.outstanding)}</TableCell>
                  {AGING.map((bucket) => (
                    <TableCell key={bucket} className="whitespace-nowrap">
                      {row.aging[bucket] === "0.00" ? "" : formatMoney(row.aging[bucket])}
                    </TableCell>
                  ))}
                  <TableCell className="text-destructive font-semibold whitespace-nowrap" data-testid="overdue">
                    {row.overdue === "0.00" ? "" : formatMoney(row.overdue)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={report.data?.meta} onPage={onPage} />
    </div>
  );
}

export function LowStockSection({ page, onPage }: { page: number; onPage: (page: number) => void }) {
  const report = useLowStockReport(page);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">{copy.lowStockHint}</p>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock />}
      {report.data?.data.length === 0 && <EmptyBlock>{copy.noLowStock}</EmptyBlock>}
      {report.data !== undefined && report.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.product}</TableHead>
                <TableHead>{copy.code}</TableHead>
                <TableHead>{copy.available}</TableHead>
                <TableHead>{copy.threshold}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {report.data.data.map((row) => (
                <TableRow key={row.sku_id}>
                  <TableCell>
                    <Link href={`/dashboard/inventory/${row.sku_id}`} className="font-medium hover:underline">
                      {row.product_name}
                    </Link>
                  </TableCell>
                  <TableCell>
                    <bdi>{row.code}</bdi>
                  </TableCell>
                  <TableCell className="font-semibold">{row.available}</TableCell>
                  <TableCell>{row.threshold}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={report.data?.meta} onPage={onPage} />
    </div>
  );
}
