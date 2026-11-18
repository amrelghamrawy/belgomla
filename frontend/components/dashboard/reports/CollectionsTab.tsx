"use client";

import Link from "next/link";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CollectionsReport, ReportAging } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type ListQuery, pageOf } from "@/lib/dashboard/list-query";
import { AGING_BUCKETS, type AgingBucket, agingSegments, reportParams } from "@/lib/dashboard/report-range";
import { useCollectionsReport, useOutstandingReport } from "@/lib/dashboard/reports";
import { formatDay } from "@/lib/format/date";
import { formatMoney, formatPercent } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { cn } from "@/lib/utils";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";
import { KpiTile } from "./KpiTile";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";

const copy = ar.dashboard.reports;

/** V2 Task 16 «التحصيل»: money collected against net sales delivered, the aging of the debts now, and who owes. */
export function CollectionsTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const report = useCollectionsReport(reportParams("collections", query));

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="collections"
        query={query}
        setQuery={setQuery}
        range={report.data}
        generatedAt={report.data?.generated_at}
      />
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={4} />}
      {report.data !== undefined && <CollectionsBody report={report.data} />}
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.outstandingTitle}</CardTitle>
        </CardHeader>
        <CardContent>
          <OutstandingSection
            page={pageOf({ page: query.debt_page ?? "" })}
            onPage={(next) => setQuery({ debt_page: next })}
          />
        </CardContent>
      </Card>
    </div>
  );
}

function CollectionsBody({ report }: { report: CollectionsReport }) {
  const { totals } = report;

  return (
    <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <KpiTile
          label={copy.netCollected}
          value={formatMoney(totals.net)}
          testId="collected-net"
          hint={
            <>
              {copy.cash} {formatMoney(totals.cash)} · {copy.credit} {formatMoney(totals.credit)} · {copy.refunds}{" "}
              <bdi>{formatMoney(totals.refunds)}</bdi>
            </>
          }
        />
        <KpiTile label={copy.delivered} value={formatMoney(totals.delivered)} testId="collected-delivered" />
        <KpiTile
          label={copy.collectionRate}
          value={totals.collection_rate === null ? "—" : `${formatPercent(totals.collection_rate)}%`}
          hint={copy.collectionRateHint}
          testId="collection-rate"
        />
      </div>
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.byMethodTitle}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.byMethodHint}</p>
        </CardHeader>
        <CardContent>
          <ul className="flex flex-col gap-1 text-sm" data-testid="collections-by-method">
            {report.by_method.map((row) => (
              <li key={row.method} className="flex justify-between gap-2">
                <span>{row.label}</span>
                <bdi className="font-medium">{formatMoney(row.amount)}</bdi>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.agingTitle}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.agingHint}</p>
        </CardHeader>
        <CardContent>
          <AgingBar aging={report.aging} />
        </CardContent>
      </Card>
      <Card className="min-w-0">
        <CardHeader>
          <CardTitle>{copy.collectionsTitle}</CardTitle>
        </CardHeader>
        <CardContent>
          {totals.net === "0.00" && totals.delivered === "0.00" && totals.refunds === "0.00" ? (
            <EmptyBlock>{copy.noCollections}</EmptyBlock>
          ) : (
            <div className="overflow-x-auto">
              <Table data-testid="collections-buckets">
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.period}</TableHead>
                    <TableHead>{copy.cash}</TableHead>
                    <TableHead>{copy.credit}</TableHead>
                    <TableHead>{copy.refunds}</TableHead>
                    <TableHead>{copy.netCollected}</TableHead>
                    <TableHead>{copy.delivered}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.buckets.map((bucket) => (
                    <TableRow key={bucket.period_start}>
                      <TableCell className="whitespace-nowrap">{formatDay(bucket.period_start)}</TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(bucket.cash)}</TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(bucket.credit)}</TableCell>
                      <TableCell className="whitespace-nowrap">
                        <bdi>{formatMoney(bucket.refunds)}</bdi>
                      </TableCell>
                      <TableCell className="font-semibold whitespace-nowrap">{formatMoney(bucket.net)}</TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(bucket.delivered)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </>
  );
}

/** One hue, darker with age (not yet due is neutral): a sequential ramp, so older debt reads heavier. */
const AGING_FILL: Record<AgingBucket, string> = {
  current: "bg-muted-foreground/35",
  "0_30": "bg-destructive/30",
  "31_60": "bg-destructive/50",
  "61_90": "bg-destructive/75",
  over_90: "bg-destructive",
};

/**
 * BR-C06: the debts now as one horizontal stacked bar from not yet due to over 90 days, segments split by a 2px
 * surface gap and growing from the start edge (right to left in RTL), with a legend that carries every amount (so
 * nothing relies on colour or hover).
 */
export function AgingBar({ aging }: { aging: ReportAging }) {
  const segments = agingSegments(aging);

  if (segments.length === 0) {
    return <EmptyBlock>{copy.noAging}</EmptyBlock>;
  }

  return (
    <figure className="flex flex-col gap-3" aria-label={copy.agingLabel}>
      <p className="flex flex-wrap items-baseline gap-x-3 text-sm">
        <span className="text-lg font-semibold" data-testid="aging-outstanding">
          {copy.agingTotal(formatMoney(aging.outstanding))}
        </span>
        <span className="text-destructive">
          {copy.overdue} {formatMoney(aging.overdue)}
        </span>
      </p>
      <div className="flex h-4 w-full gap-0.5 overflow-hidden rounded-full" aria-hidden data-testid="aging-bar">
        {segments.map((segment) => (
          <span
            key={segment.key}
            data-testid={`aging-${segment.key}`}
            className={cn("block h-full min-w-1 first:rounded-s-full last:rounded-e-full", AGING_FILL[segment.key])}
            style={{ width: `${segment.percent}%` }}
          />
        ))}
      </div>
      <figcaption>
        <ul className="grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-3 lg:grid-cols-5">
          {AGING_BUCKETS.map((key) => (
            <li key={key} className="flex items-center justify-between gap-2 sm:justify-start">
              <span className="flex items-center gap-1">
                <span aria-hidden className={cn("inline-block size-2.5 rounded-sm", AGING_FILL[key])} />
                {copy.aging[key]}
              </span>
              <span className="font-semibold whitespace-nowrap">{formatMoney(aging[key])}</span>
            </li>
          ))}
        </ul>
      </figcaption>
    </figure>
  );
}

/** Outstanding amount per customer over delivered orders, largest first (D59), aged by days past due (BR-C06). */
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
          <Table data-testid="outstanding-report">
            <TableHeader>
              <TableRow>
                <TableHead>{copy.customer}</TableHead>
                <TableHead>{copy.ordersCount}</TableHead>
                <TableHead>{copy.total}</TableHead>
                <TableHead>{copy.collected}</TableHead>
                <TableHead>{copy.outstanding}</TableHead>
                {AGING_BUCKETS.map((bucket) => (
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
                  {AGING_BUCKETS.map((bucket) => (
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
