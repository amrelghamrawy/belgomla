"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { ReportMovements } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type ListQuery, pageOf } from "@/lib/dashboard/list-query";
import { daysValue, reportParams } from "@/lib/dashboard/report-range";
import { useInventoryReport, useLowStockReport } from "@/lib/dashboard/reports";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";
import { KpiTile } from "./KpiTile";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";

const copy = ar.dashboard.reports;
const MOVEMENTS: (keyof ReportMovements)[] = ["opening", "adjustment", "sale", "return"];

/**
 * V2 Task 16 «المخزون»: stock valued at the selling price (D94 a: no purchase cost), dead stock, days of cover (∞
 * when nothing sold), the movements of the range by type, and the low-stock list (D36).
 */
export function InventoryTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const page = pageOf(query);
  const params = reportParams("inventory", query);
  const report = useInventoryReport(page === 1 ? params : { ...params, page });
  const summary = report.data?.summary;

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="inventory"
        query={query}
        setQuery={setQuery}
        range={report.data}
        generatedAt={report.data?.generated_at}
      >
        <label className="flex flex-col gap-1 text-xs">
          {copy.deadDays}
          <Input
            type="number"
            min={1}
            inputMode="numeric"
            className="w-28"
            defaultValue={query.dead_days ?? report.data?.dead_days ?? 60}
            onChange={(event) => setQuery({ dead_days: daysValue(event.target.value) })}
          />
        </label>
        <label className="flex h-8 items-center gap-2 text-sm">
          <Checkbox
            checked={query.dead_only === "1"}
            onCheckedChange={(checked) => setQuery({ dead_only: checked === true ? "1" : null })}
          />
          {copy.deadOnly}
        </label>
      </ReportToolbar>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {report.data !== undefined && summary !== undefined && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <KpiTile
              label={copy.stockValue}
              value={formatMoney(summary.stock_value)}
              hint={copy.skus(summary.skus)}
              testId="stock-value"
            />
            <KpiTile
              label={copy.deadCount}
              value={summary.dead_count}
              hint={copy.deadValue(formatMoney(summary.dead_value))}
              testId="dead-count"
            />
            <KpiTile label={copy.unitsSold} value={summary.sold_base} testId="units-sold" />
            <div className="col-span-2 flex min-w-0 flex-col gap-1 rounded-md border p-3 lg:col-span-1">
              <p className="text-muted-foreground text-xs">{copy.movementsTitle}</p>
              <dl className="grid grid-cols-2 gap-x-3 gap-y-0.5 text-sm" data-testid="movements">
                {MOVEMENTS.map((type) => (
                  <div key={type} className="flex justify-between gap-2">
                    <dt className="text-muted-foreground">{copy.movements[type]}</dt>
                    <dd className="font-semibold">
                      <bdi>{summary.movements[type]}</bdi>
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
          <p className="text-muted-foreground text-xs">{copy.stockValueHint}</p>
          <Card className="min-w-0">
            <CardHeader>
              <CardTitle>{copy.inventoryTitle}</CardTitle>
              <p className="text-muted-foreground text-xs">{copy.inventoryHint}</p>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {report.data.data.length === 0 ? (
                <EmptyBlock>{copy.noInventory}</EmptyBlock>
              ) : (
                <div className="overflow-x-auto">
                  <Table data-testid="inventory-report">
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.product}</TableHead>
                        <TableHead>{copy.code}</TableHead>
                        <TableHead>{copy.onHand}</TableHead>
                        <TableHead>{copy.available}</TableHead>
                        <TableHead>{copy.stockValue}</TableHead>
                        <TableHead>{copy.unitsSold}</TableHead>
                        <TableHead>{copy.daysOfCover}</TableHead>
                        <TableHead>{copy.lastSold}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {report.data.data.map((row) => (
                        <TableRow key={row.sku_id}>
                          <TableCell className="min-w-40">
                            <Link href={`/dashboard/inventory/${row.sku_id}`} className="font-medium hover:underline">
                              {row.product_name}
                            </Link>
                            {row.dead && (
                              <Badge variant="destructive" className="ms-1" data-testid="dead">
                                {copy.deadBadge}
                              </Badge>
                            )}
                            {row.sku_name !== null && (
                              <span className="text-muted-foreground block text-xs">{row.sku_name}</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <bdi>{row.sku_code}</bdi>
                          </TableCell>
                          <TableCell>{row.on_hand}</TableCell>
                          <TableCell>{row.available}</TableCell>
                          <TableCell className="font-semibold whitespace-nowrap">
                            {formatMoney(row.stock_value)}
                          </TableCell>
                          <TableCell>{row.sold_base}</TableCell>
                          <TableCell data-testid="cover">
                            {row.days_of_cover === null ? (
                              <span aria-label={copy.noSalesCover}>{copy.infinity}</span>
                            ) : (
                              row.days_of_cover
                            )}
                          </TableCell>
                          <TableCell className="whitespace-nowrap">
                            {row.last_sold_at === null ? "—" : formatDate(row.last_sold_at)}
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
        </>
      )}
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.lowStockTitle}</CardTitle>
        </CardHeader>
        <CardContent>
          <LowStockSection
            page={pageOf({ page: query.low_page ?? "" })}
            onPage={(next) => setQuery({ low_page: next, page: query.page ?? null })}
          />
        </CardContent>
      </Card>
    </div>
  );
}

/** SKUs at or below MOQ × conversion factor (D36), as the V1 low-stock report listed them. */
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
          <Table data-testid="low-stock-report">
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
