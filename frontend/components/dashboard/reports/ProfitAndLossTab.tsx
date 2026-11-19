"use client";

import Link from "next/link";
import type { ReactNode } from "react";

import { Card, CardContent } from "@/components/ui/card";
import type { ProfitAndLossFigures, ProfitAndLossReport } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import type { ListQuery } from "@/lib/dashboard/list-query";
import { reportParams } from "@/lib/dashboard/report-range";
import { useProfitAndLossReport } from "@/lib/dashboard/reports";
import { formatMoney, formatPercent } from "@/lib/format/money";
import { cn } from "@/lib/utils";

import { ErrorBlock, LoadingBlock } from "../common/States";
import { ReportToolbar, type SetQuery } from "./ReportToolbar";

const copy = ar.dashboard.reports;
const pl = copy.pl;

/** V2 Task 19 «الأرباح والخسائر» (D100): the statement top to bottom, beside the range before. */
export function ProfitAndLossTab({ query, setQuery }: { query: ListQuery; setQuery: SetQuery }) {
  const report = useProfitAndLossReport(reportParams("profit-and-loss", query));

  return (
    <div className="flex flex-col gap-4">
      <ReportToolbar
        report="profit-and-loss"
        query={query}
        setQuery={setQuery}
        range={report.data}
        generatedAt={report.data?.generated_at}
      />
      <p className="text-muted-foreground text-xs">{pl.hint}</p>
      {report.isError && <ErrorBlock error={report.error} onRetry={() => void report.refetch()} />}
      {report.isPending && <LoadingBlock rows={6} />}
      {report.data !== undefined && <Statement report={report.data} />}
    </div>
  );
}

/** A figure, «غير مكتمل» when unknown. */
function amount(value: string | null): string {
  return value === null ? pl.unknown : formatMoney(value);
}

/** An amount taken away: «− 600.00 ج.م» (a negative one gave something back and shows as +). */
function minus(value: string | null): string {
  if (value === null) {
    return pl.unknown;
  }

  return value.startsWith("-") ? `+ ${formatMoney(value.slice(1))}` : `− ${formatMoney(value)}`;
}

export function Statement({ report }: { report: ProfitAndLossReport }) {
  const { current, previous } = report;
  const net = current.net_profit;
  const isLoss = net !== null && net.startsWith("-");

  // D104: a statement, not a stretched list: one column of items, the period and the one before aligned on the
  // end, sub-items indented and quiet, subtotals ruled, and the bottom line set apart.
  return (
    <Card className="min-w-0">
      <CardContent className="flex flex-col pt-2">
        <div className="mx-auto w-full max-w-3xl">
          <div className="text-muted-foreground grid grid-cols-[1fr_auto_auto] gap-x-3 border-b py-2 text-xs font-medium sm:gap-x-10">
            <span>{pl.item}</span>
            <span className="sm:min-w-28 text-end">{pl.thisPeriod}</span>
            <span className="sm:min-w-28 text-end">{pl.previous}</span>
          </div>
          <Line label={pl.netSales} value={amount(current.net_sales)} before={amount(previous.net_sales)} />
          <Line label={pl.costOfGoods} value={minus(current.cost_of_goods)} before={minus(previous.cost_of_goods)} />
          <Line
            label={pl.grossProfit}
            value={amount(current.gross_profit)}
            before={amount(previous.gross_profit)}
            total
          />
          <Line label={pl.stockLosses} value={minus(current.stock_losses)} before={minus(previous.stock_losses)} />
          <Breakdown items={current.losses_by_reason} labels={pl.reasons} />
          <Line label={pl.expenses} value={minus(current.expenses)} before={minus(previous.expenses)} />
          <Breakdown items={current.expenses_by_category} labels={pl.categories} />
          <Line
            label={isLoss ? `${pl.netProfit} (${pl.loss})` : pl.netProfit}
            value={amount(net)}
            before={amount(previous.net_profit)}
            result
            tone={isLoss ? "loss" : undefined}
            testId="net-profit"
          />
          <Line
            label={pl.netMargin}
            value={current.net_margin === null ? "—" : `${formatPercent(current.net_margin)}%`}
            before={previous.net_margin === null ? "—" : `${formatPercent(previous.net_margin)}%`}
          />
          <Missing figures={current} />
        </div>
      </CardContent>
    </Card>
  );
}

function Line({
  label,
  value,
  before,
  total = false,
  result = false,
  tone,
  testId,
}: {
  label: ReactNode;
  value: string;
  before: string;
  total?: boolean;
  result?: boolean;
  tone?: "loss";
  testId?: string;
}) {
  const unknown = value === pl.unknown;

  return (
    <div
      className={cn(
        "grid grid-cols-[1fr_auto_auto] items-baseline gap-x-3 py-2.5 text-sm sm:gap-x-10 sm:text-base",
        total && "border-t font-bold",
        result && "bg-primary-soft -mx-3 my-2 rounded-2xl border-0 px-3 py-3.5 text-lg font-bold",
      )}
    >
      <span>{label}</span>
      <bdi
        className={cn(
          "sm:min-w-28 text-end tabular-nums",
          tone === "loss" && "text-destructive",
          unknown && "text-warn text-sm font-bold",
        )}
        data-testid={testId}
      >
        {value}
      </bdi>
      <bdi className="text-muted-foreground sm:min-w-28 text-end text-sm font-normal tabular-nums">{before}</bdi>
    </div>
  );
}

function Breakdown({ items, labels }: { items: Record<string, string>; labels: Record<string, string> }) {
  const entries = Object.entries(items);

  if (entries.length === 0) {
    return null;
  }

  return (
    <ul className="text-muted-foreground flex flex-col pb-1 text-sm">
      {entries.map(([key, value]) => (
        <li key={key} className="grid grid-cols-[1fr_auto_auto] gap-x-3 py-1 ps-5 sm:gap-x-10">
          <span>{labels[key] ?? key}</span>
          <bdi className="sm:min-w-28 text-end tabular-nums">{formatMoney(value)}</bdi>
          <span className="sm:min-w-28" />
        </li>
      ))}
    </ul>
  );
}

/** D96 f D100 g: why a figure is unknown, and where to fix it. */
function Missing({ figures }: { figures: ProfitAndLossFigures }) {
  if (figures.uncosted_lines === 0 && figures.uncosted_adjustments === 0) {
    return null;
  }

  return (
    <p className="text-muted-foreground pt-2 text-xs" data-testid="pl-missing">
      {[
        figures.uncosted_lines > 0 ? pl.uncostedLines(figures.uncosted_lines) : null,
        figures.uncosted_adjustments > 0 ? pl.uncostedAdjustments(figures.uncosted_adjustments) : null,
      ]
        .filter(Boolean)
        .join(" · ")}
      {" — "}
      <Link href="/dashboard/inventory" className="underline">
        {pl.setCosts}
      </Link>
    </p>
  );
}
