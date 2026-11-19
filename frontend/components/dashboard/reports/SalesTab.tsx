"use client";

import { TriangleAlertIcon } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { SalesAnalyticsReport } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import type { ListQuery } from "@/lib/dashboard/list-query";
import { deltaPercent, reportParams } from "@/lib/dashboard/report-range";
import { useSalesAnalytics } from "@/lib/dashboard/reports";
import { formatDay } from "@/lib/format/date";
import { formatMoney, formatPercent } from "@/lib/format/money";

import { DetailsBlock } from "../common/DetailsBlock";
import { Money } from "../common/Money";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";
import { BarList } from "../overview/BarList";
import { KpiTile } from "./KpiTile";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";
import { SalesTrendChart } from "./SalesTrendChart";

const copy = ar.dashboard.reports;

type Figures = { orders: number; gross: string; discounts: string; returns: string; net: string };

/** The money columns shared by the section, customer group and bucket tables. */
function FigureHeads() {
  return (
    <>
      <TableHead>{copy.orders}</TableHead>
      <TableHead>{copy.gross}</TableHead>
      <TableHead>{copy.discounts}</TableHead>
      <TableHead>{copy.returns}</TableHead>
      <TableHead>{copy.net}</TableHead>
    </>
  );
}

function FigureCells({ row }: { row: Figures }) {
  return (
    <>
      <TableCell>{row.orders}</TableCell>
      <TableCell>
        <Money value={row.gross} />
      </TableCell>
      <TableCell>
        <Money value={row.discounts} />
      </TableCell>
      <TableCell>
        <Money value={row.returns} />
      </TableCell>
      <TableCell className="font-semibold">
        <Money value={row.net} />
      </TableCell>
    </>
  );
}

/** V2 Task 16 «المبيعات»: KPI tiles against the previous period, the net trend with the previous period, the splits. */
export function SalesTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const report = useSalesAnalytics(reportParams("sales-analytics", query));
  const data = report.data;

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="sales-analytics"
        query={query}
        setQuery={setQuery}
        range={data}
        generatedAt={data?.generated_at}
      />
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {data !== undefined && <SalesBody report={data} />}
    </div>
  );
}

function SalesBody({ report }: { report: SalesAnalyticsReport }) {
  const { totals } = report;
  const before = report.previous.totals;
  const empty = totals.orders === 0 && totals.returns === "0.00" && before.orders === 0 && before.returns === "0.00";

  return (
    <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
        <KpiTile
          label={copy.totalSales}
          value={formatMoney(totals.net)}
          delta={deltaPercent(totals.net, before.net)}
          testId="sales-net"
        />
        <KpiTile
          label={copy.gross}
          value={formatMoney(totals.gross)}
          delta={deltaPercent(totals.gross, before.gross)}
          testId="sales-gross"
        />
        <KpiTile
          label={copy.discounts}
          value={formatMoney(totals.discounts)}
          delta={deltaPercent(totals.discounts, before.discounts)}
          upIsGood={false}
          testId="sales-discounts"
        />
        <KpiTile
          label={copy.totalReturns}
          value={formatMoney(totals.returns)}
          delta={deltaPercent(totals.returns, before.returns)}
          upIsGood={false}
          testId="sales-returns"
        />
        <KpiTile
          label={copy.totalOrders}
          value={totals.orders}
          delta={deltaPercent(totals.orders, before.orders)}
          testId="sales-orders"
        />
        <KpiTile
          label={copy.averageOrder}
          value={totals.average_order === null ? "—" : formatMoney(totals.average_order)}
          delta={deltaPercent(totals.average_order, before.average_order)}
          testId="sales-average"
        />
      </div>
      <p className="text-muted-foreground -mt-1 text-xs">{copy.analyticsHint}</p>
      <ProfitTiles report={report} />
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.trendTitle}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.salesHint}</p>
        </CardHeader>
        <CardContent>
          {empty ? <EmptyBlock>{copy.noSales}</EmptyBlock> : <SalesTrendChart report={report} />}
        </CardContent>
      </Card>
      {/* D104: the splits as ranked bars of net sales; the full figures fold under each. */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>{copy.bySection}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {report.by_section.length === 0 ? (
              <EmptyBlock>{copy.noSales}</EmptyBlock>
            ) : (
              <>
                <BarList
                  label={copy.bySection}
                  items={report.by_section.map((row) => ({
                    key: row.kind,
                    label: row.label,
                    value: Math.max(Number(row.net), 0),
                    display: formatMoney(row.net),
                  }))}
                />
                <DetailsBlock summary={copy.detailsTable}>
                  <Table data-testid="sales-by-section">
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.section}</TableHead>
                        <FigureHeads />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {report.by_section.map((row) => (
                        <TableRow key={row.kind}>
                          <TableCell className="font-medium">{row.label}</TableCell>
                          <FigureCells row={row} />
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </DetailsBlock>
              </>
            )}
          </CardContent>
        </Card>
        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>{copy.byCustomerGroup}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {report.by_customer_group.length === 0 ? (
              <EmptyBlock>{copy.noSales}</EmptyBlock>
            ) : (
              <>
                <BarList
                  label={copy.byCustomerGroup}
                  items={report.by_customer_group.map((row) => ({
                    key: String(row.customer_group_id ?? "none"),
                    label: row.name ?? copy.noGroup,
                    value: Math.max(Number(row.net), 0),
                    display: formatMoney(row.net),
                  }))}
                />
                <DetailsBlock summary={copy.detailsTable}>
                  <Table data-testid="sales-by-group">
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.groupColumn}</TableHead>
                        <FigureHeads />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {report.by_customer_group.map((row) => (
                        <TableRow key={row.customer_group_id ?? "none"}>
                          <TableCell className="font-medium">{row.name ?? copy.noGroup}</TableCell>
                          <FigureCells row={row} />
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </DetailsBlock>
              </>
            )}
          </CardContent>
        </Card>
      </div>
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.bucketsTitle}</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailsBlock summary={copy.showTableRows(report.buckets.length)}>
            <Table data-testid="sales-buckets">
              <TableHeader>
                <TableRow>
                  <TableHead>{copy.period}</TableHead>
                  <FigureHeads />
                </TableRow>
              </TableHeader>
              <TableBody>
                {report.buckets.map((bucket) => (
                  <TableRow key={bucket.period_start}>
                    <TableCell className="whitespace-nowrap">{formatDay(bucket.period_start)}</TableCell>
                    <FigureCells row={bucket} />
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </DetailsBlock>
        </CardContent>
      </Card>
    </>
  );
}

/**
 * D98: the cost of what was sold, the profit and its margin. While a line went out without a cost the profit is
 * unknown, said so with how many lines, never a partial figure.
 */
export function ProfitTiles({ report }: { report: SalesAnalyticsReport }) {
  const { profit } = report;

  return (
    <div className="flex flex-col gap-2">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <KpiTile label={copy.cost} value={formatMoney(profit.cost)} testId="sales-cost" />
        <KpiTile
          label={copy.profit}
          value={profit.profit === null ? <Unknown /> : formatMoney(profit.profit)}
          testId="sales-profit"
        />
        <KpiTile
          label={copy.margin}
          value={profit.margin === null ? "—" : `${formatPercent(profit.margin)}%`}
          testId="sales-margin"
        />
      </div>
      {profit.uncosted_lines > 0 && (
        <p className="text-warn flex items-center gap-1.5 text-xs font-medium" data-testid="uncosted-hint">
          <TriangleAlertIcon aria-hidden className="size-3.5 shrink-0" />
          {copy.uncostedHint(profit.uncosted_lines)}
        </p>
      )}
    </div>
  );
}

/** D98, D104: a figure that cannot be known yet, said as a small warning chip, not as a big value. */
function Unknown() {
  return (
    <span className="bg-warn-soft text-warn inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-sm font-bold">
      <TriangleAlertIcon aria-hidden className="size-3.5" />
      {copy.profitUnknown}
    </span>
  );
}
