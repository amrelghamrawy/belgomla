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

  return (
    <Card className="min-w-0">
      <CardContent className="flex flex-col gap-1 pt-6">
        <Line label={pl.netSales} value={amount(current.net_sales)} before={amount(previous.net_sales)} />
        <Line label={pl.costOfGoods} value={minus(current.cost_of_goods)} before={minus(previous.cost_of_goods)} />
        <Line label={pl.grossProfit} value={amount(current.gross_profit)} before={amount(previous.gross_profit)} total />
        <Line label={pl.stockLosses} value={minus(current.stock_losses)} before={minus(previous.stock_losses)} />
        <Breakdown items={current.losses_by_reason} labels={pl.reasons} />
        <Line label={pl.expenses} value={minus(current.expenses)} before={minus(previous.expenses)} />
        <Breakdown items={current.expenses_by_category} labels={pl.categories} />
        <Line
          label={isLoss ? `${pl.netProfit} (${pl.loss})` : pl.netProfit}
          value={amount(net)}
          before={amount(previous.net_profit)}
          total
          tone={isLoss ? "loss" : undefined}
          testId="net-profit"
        />
        <Line
          label={pl.netMargin}
          value={current.net_margin === null ? "—" : `${formatPercent(current.net_margin)}%`}
          before={previous.net_margin === null ? "—" : `${formatPercent(previous.net_margin)}%`}
        />
        <Missing figures={current} />
      </CardContent>
    </Card>
  );
}

function Line({
  label,
  value,
  before,
  total = false,
  tone,
  testId,
}: {
  label: ReactNode;
  value: string;
  before: string;
  total?: boolean;
  tone?: "loss";
  testId?: string;
}) {
  return (
    <div className={cn("flex flex-wrap items-baseline justify-between gap-2 py-1.5", total && "border-t font-semibold")}>
      <span>{label}</span>
      <span className="flex items-baseline gap-3">
        <bdi className={cn(tone === "loss" && "text-destructive")} data-testid={testId}>
          {value}
        </bdi>
        <span className="text-muted-foreground text-xs">
          {pl.previous}: <bdi>{before}</bdi>
        </span>
      </span>
    </div>
  );
}

function Breakdown({ items, labels }: { items: Record<string, string>; labels: Record<string, string> }) {
  const entries = Object.entries(items);

  if (entries.length === 0) {
    return null;
  }

  return (
    <ul className="text-muted-foreground flex flex-col gap-0.5 ps-4 text-xs">
      {entries.map(([key, value]) => (
        <li key={key} className="flex justify-between gap-2">
          <span>{labels[key] ?? key}</span>
          <bdi>{formatMoney(value)}</bdi>
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
