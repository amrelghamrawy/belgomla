"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CustomerReportView } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type ListQuery, pageOf } from "@/lib/dashboard/list-query";
import { daysValue, reportParams } from "@/lib/dashboard/report-range";
import { useCustomerReport } from "@/lib/dashboard/reports";
import { formatDate } from "@/lib/format/date";
import { formatPhone } from "@/lib/format/phone";

import { Money } from "../common/Money";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";

const copy = ar.dashboard.reports;
const VIEWS: CustomerReportView[] = ["top", "dormant"];

/**
 * V2 Task 16 «العملاء»: the best customers of the range by net revenue, or the dormant ones (no order for
 * `dormant_days`, D94 h), with their order rhythm and debts.
 */
export function CustomersTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const page = pageOf(query);
  const params = reportParams("customers", query);
  const report = useCustomerReport(page === 1 ? params : { ...params, page });
  const view = query.view === "dormant" ? "dormant" : "top";

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="customers"
        query={query}
        setQuery={setQuery}
        range={report.data}
        generatedAt={report.data?.generated_at}
      >
        <label className="flex flex-col gap-1 text-xs">
          {copy.dormantDays}
          <Input
            type="number"
            min={1}
            inputMode="numeric"
            className="w-28"
            defaultValue={query.dormant_days ?? report.data?.dormant_days ?? 45}
            onChange={(event) => setQuery({ dormant_days: daysValue(event.target.value) })}
          />
        </label>
      </ReportToolbar>
      <div className="flex flex-wrap gap-1" role="group" aria-label={copy.view}>
        {VIEWS.map((option) => (
          <Button
            key={option}
            size="sm"
            variant={view === option ? "default" : "outline"}
            aria-pressed={view === option}
            onClick={() => setQuery({ view: option === "top" ? null : option })}
          >
            {copy.views[option]}
          </Button>
        ))}
      </div>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {report.data !== undefined && (
        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>{copy.views[view]}</CardTitle>
            <p className="text-muted-foreground text-xs">{copy.customersHint}</p>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {report.data.data.length === 0 ? (
              <EmptyBlock>{copy.noCustomers}</EmptyBlock>
            ) : (
              <div className="overflow-x-auto">
                <Table data-testid="customers-report">
                  <TableHeader>
                    <TableRow>
                      <TableHead>{copy.customer}</TableHead>
                      <TableHead>{copy.groupColumn}</TableHead>
                      <TableHead>{copy.orders}</TableHead>
                      <TableHead>{copy.revenue}</TableHead>
                      <TableHead>{copy.averageOrder}</TableHead>
                      <TableHead>{copy.lastOrder}</TableHead>
                      <TableHead>{copy.daysSince}</TableHead>
                      <TableHead>{copy.interval}</TableHead>
                      <TableHead>{copy.lifetimeOrders}</TableHead>
                      <TableHead>{copy.outstanding}</TableHead>
                      <TableHead>{copy.overdue}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {report.data.data.map((row) => (
                      <TableRow key={row.customer.id}>
                        <TableCell className="min-w-40">
                          <Link
                            href={`/dashboard/customers/${row.customer.id}`}
                            className="font-medium hover:underline"
                          >
                            {row.customer.name}
                          </Link>
                          {row.dormant && (
                            <Badge variant="outline" className="ms-1" data-testid="dormant">
                              {copy.dormantBadge}
                            </Badge>
                          )}
                          <bdi className="text-muted-foreground block text-xs">{formatPhone(row.customer.phone)}</bdi>
                        </TableCell>
                        <TableCell>{row.customer_group?.name ?? "—"}</TableCell>
                        <TableCell>{row.orders}</TableCell>
                        <TableCell className="font-semibold">
                          <Money value={row.revenue} />
                        </TableCell>
                        <TableCell className="whitespace-nowrap">
                          {row.average_order === null ? "—" : <Money value={row.average_order} />}
                        </TableCell>
                        <TableCell className="whitespace-nowrap">{formatDate(row.last_order_at)}</TableCell>
                        <TableCell>{row.days_since_last_order}</TableCell>
                        <TableCell>{row.average_interval_days ?? "—"}</TableCell>
                        <TableCell>{row.lifetime_orders}</TableCell>
                        <TableCell className="whitespace-nowrap">
                          {row.outstanding === "0.00" ? "" : <Money value={row.outstanding} />}
                        </TableCell>
                        <TableCell className="text-destructive font-semibold whitespace-nowrap">
                          {row.overdue === "0.00" ? "" : <Money value={row.overdue} />}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
            <Pager meta={report.data.meta} onPage={(next) => setQuery({ page: next })} />
          </CardContent>
        </Card>
      )}
    </div>
  );
}
