"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { NativeSelect } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { ProductPerformanceRow, ProductReportSort } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type ListQuery, pageOf } from "@/lib/dashboard/list-query";
import { reportParams } from "@/lib/dashboard/report-range";
import { useBundleReport, useProductReport } from "@/lib/dashboard/reports";
import { formatDate } from "@/lib/format/date";
import { formatMoney, formatPercent } from "@/lib/format/money";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";
import { BarList } from "../overview/BarList";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";

const copy = ar.dashboard.reports;
const SORTS: ProductReportSort[] = ["revenue", "quantity", "return_rate"];

const productHref = (row: { is_bundle: boolean; product_id: number }) =>
  row.is_bundle ? `/dashboard/bundles/${row.product_id}` : `/dashboard/products/${row.product_id}`;

/** The bar of a row on the chosen order: net revenue, base units, or the return rate. */
function barOf(row: ProductPerformanceRow, sort: ProductReportSort): { value: number; display: string } {
  if (sort === "quantity") {
    return { value: row.quantity_base, display: String(row.quantity_base) };
  }

  if (sort === "return_rate") {
    return {
      value: Number(row.return_rate ?? 0),
      display: row.return_rate === null ? "—" : `${formatPercent(row.return_rate)}%`,
    };
  }

  return { value: Math.max(Number(row.revenue), 0), display: formatMoney(row.revenue) };
}

/** V2 Task 16 «المنتجات»: the top 20 on the chosen order as bars, every SKU in a paged table, and the bundles. */
export function ProductsTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const params = reportParams("products", query);
  const page = pageOf(query);
  const top = useProductReport(params);
  const report = useProductReport(page === 1 ? params : { ...params, page });
  const sort: ProductReportSort = top.data?.sort ?? (query.sort as ProductReportSort | undefined) ?? "revenue";

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="products"
        query={query}
        setQuery={setQuery}
        range={report.data}
        generatedAt={report.data?.generated_at}
      >
        <NativeSelect
          aria-label={copy.sort}
          value={query.sort ?? "revenue"}
          onChange={(event) => setQuery({ sort: event.target.value === "revenue" ? null : event.target.value })}
        >
          {SORTS.map((option) => (
            <option key={option} value={option}>
              {copy.sorts[option]}
            </option>
          ))}
        </NativeSelect>
      </ReportToolbar>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {report.data !== undefined && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Card className="min-w-0">
            <CardHeader>
              <CardTitle>{copy.topProducts}</CardTitle>
              <p className="text-muted-foreground text-xs">{copy.sorts[sort]}</p>
            </CardHeader>
            <CardContent>
              {top.data?.data.length === 0 && <EmptyBlock>{copy.noProducts}</EmptyBlock>}
              <BarList
                label={copy.topProducts}
                testId="top-products"
                items={(top.data?.data ?? []).map((row) => ({
                  key: String(row.sku_id),
                  label: row.sku_name === null ? row.product_name : `${row.product_name} — ${row.sku_name}`,
                  href: productHref(row),
                  ...barOf(row, sort),
                }))}
              />
            </CardContent>
          </Card>
          <Card className="min-w-0 lg:col-span-2">
            <CardHeader>
              <CardTitle>{copy.productsTitle}</CardTitle>
              <p className="text-muted-foreground text-xs">{copy.productsHint}</p>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {report.data.data.length === 0 ? (
                <EmptyBlock>{copy.noProducts}</EmptyBlock>
              ) : (
                <div className="overflow-x-auto">
                  <Table data-testid="products-report">
                    <TableHeader>
                      <TableRow>
                        <TableHead>{copy.product}</TableHead>
                        <TableHead>{copy.code}</TableHead>
                        <TableHead>{copy.quantity}</TableHead>
                        <TableHead>{copy.quantityBase}</TableHead>
                        <TableHead>{copy.revenue}</TableHead>
                        <TableHead>{copy.returns}</TableHead>
                        <TableHead>{copy.returnRate}</TableHead>
                        <TableHead>{copy.costShort}</TableHead>
                        <TableHead>{copy.profit}</TableHead>
                        <TableHead>{copy.margin}</TableHead>
                        <TableHead>{copy.customersCount}</TableHead>
                        <TableHead>{copy.lastSold}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {report.data.data.map((row) => (
                        <TableRow key={row.sku_id}>
                          <TableCell className="min-w-40">
                            <Link href={productHref(row)} className="font-medium hover:underline">
                              {row.product_name}
                            </Link>
                            {row.is_bundle && (
                              <Badge variant="secondary" className="ms-1">
                                {copy.bundleBadge}
                              </Badge>
                            )}
                            {row.sku_name !== null && (
                              <span className="text-muted-foreground block text-xs">{row.sku_name}</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <bdi>{row.sku_code}</bdi>
                          </TableCell>
                          <TableCell>{row.quantity}</TableCell>
                          <TableCell>
                            {row.quantity_base}
                            {row.bundle_quantity_base > 0 && (
                              <span
                                className="text-muted-foreground block text-xs whitespace-nowrap"
                                data-testid="in-bundles"
                              >
                                {copy.inBundles}: {row.bundle_quantity_base}
                              </span>
                            )}
                          </TableCell>
                          <TableCell className="font-semibold whitespace-nowrap">{formatMoney(row.revenue)}</TableCell>
                          <TableCell className="whitespace-nowrap">{formatMoney(row.returns)}</TableCell>
                          <TableCell className="whitespace-nowrap">
                            {row.return_rate === null ? "—" : `${formatPercent(row.return_rate)}%`}
                          </TableCell>
                          <TableCell className="whitespace-nowrap">{formatMoney(row.cost)}</TableCell>
                          <TableCell className="whitespace-nowrap" data-testid={`profit-${row.sku_id}`}>
                            {row.profit === null ? copy.profitUnknown : formatMoney(row.profit)}
                          </TableCell>
                          <TableCell className="whitespace-nowrap">
                            {row.margin === null ? "—" : `${formatPercent(row.margin)}%`}
                          </TableCell>
                          <TableCell>{row.customers}</TableCell>
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
        </div>
      )}
      <BundlesCards from={query.from} to={query.to} />
    </div>
  );
}

/**
 * BR-P15, D93: the bundles delivered in the tab's date range and the base units of each component they consumed
 * (the V2 Task 15 bundle report, folded into this tab).
 */
export function BundlesCards({ from, to }: { from?: string; to?: string }) {
  const report = useBundleReport({ from, to });

  if (report.isError) {
    return <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />;
  }

  if (report.data === undefined) {
    return null;
  }

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.bundlesTitle}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.bundlesHint}</p>
        </CardHeader>
        <CardContent>
          {report.data.bundles.length === 0 ? (
            <EmptyBlock>{copy.noBundles}</EmptyBlock>
          ) : (
            <div className="overflow-x-auto">
              <Table data-testid="bundle-report">
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.bundleName}</TableHead>
                    <TableHead>{copy.bundlesSold}</TableHead>
                    <TableHead>{copy.bundlesTotal}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.data.bundles.map((row) => (
                    <TableRow key={row.product_id}>
                      <TableCell>
                        <Link href={`/dashboard/bundles/${row.product_id}`} className="font-medium hover:underline">
                          {row.name}
                        </Link>{" "}
                        <bdi className="text-muted-foreground text-xs">{row.sku_code}</bdi>
                      </TableCell>
                      <TableCell>{row.quantity}</TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(row.total)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.componentsTitle}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.componentsHint}</p>
        </CardHeader>
        <CardContent>
          {report.data.components.length === 0 ? (
            <EmptyBlock>{copy.noBundles}</EmptyBlock>
          ) : (
            <div className="overflow-x-auto">
              <Table data-testid="bundle-components-report">
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.product}</TableHead>
                    <TableHead>{copy.code}</TableHead>
                    <TableHead>{copy.quantityBase}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.data.components.map((row) => (
                    <TableRow key={row.sku_id}>
                      <TableCell>
                        <Link href={`/dashboard/inventory/${row.sku_id}`} className="font-medium hover:underline">
                          {row.product_name}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <bdi>{row.sku_code}</bdi>
                      </TableCell>
                      <TableCell className="font-semibold">{row.quantity_base}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
