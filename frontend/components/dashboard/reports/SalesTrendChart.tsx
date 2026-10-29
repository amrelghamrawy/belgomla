"use client";

import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  type TooltipContentProps,
  type TooltipValueType,
  XAxis,
  YAxis,
} from "recharts";

import type { SalesAnalyticsReport } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { alignPrevious, type TrendPoint } from "@/lib/dashboard/report-range";
import { formatDay } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

import { compact } from "./SalesChart";

const copy = ar.dashboard.reports;

type Point = TrendPoint & { label: string };

function TrendTooltip({ active, payload }: TooltipContentProps<TooltipValueType, string | number>) {
  const point = payload?.[0]?.payload as Point | undefined;

  if (!active || point === undefined) {
    return null;
  }

  return (
    <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md">
      <p className="font-medium">{point.label}</p>
      <p>
        {copy.net}: {formatMoney(point.netMoney)}
      </p>
      {point.previousMoney !== null && point.previousStart !== null && (
        <p className="text-muted-foreground">
          {copy.previousPeriod} ({formatDay(point.previousStart)}): {formatMoney(point.previousMoney)}
        </p>
      )}
    </div>
  );
}

/**
 * Net sales per bucket as a 2px line in the brand colour, with the previous period of the same length as a muted
 * dashed line aligned by position; a two-entry legend, a recessive grid, a crosshair tooltip with both values; time
 * runs right to left in RTL (reversed axis, value axis on the right), compact ticks. The buckets table carries the
 * same numbers.
 */
export function SalesTrendChart({ report }: { report: SalesAnalyticsReport }) {
  const points: Point[] = alignPrevious(report.buckets, report.previous.buckets).map((point) => ({
    ...point,
    label: formatDay(point.periodStart),
  }));

  return (
    <figure
      aria-label={copy.trendLabel(formatDay(report.from), formatDay(report.to))}
      className="flex h-72 w-full flex-col gap-1"
    >
      <figcaption className="text-muted-foreground flex flex-wrap gap-4 text-xs">
        <span className="flex items-center gap-1">
          <span aria-hidden className="bg-primary inline-block h-0.5 w-4 rounded-full" />
          {copy.currentPeriod}
        </span>
        <span className="flex items-center gap-1">
          <span aria-hidden className="border-muted-foreground inline-block w-4 border-t-2 border-dashed" />
          {copy.previousPeriod}
        </span>
      </figcaption>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={points} margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" strokeWidth={1} />
          <XAxis
            dataKey="label"
            reversed
            tickLine={false}
            axisLine={{ stroke: "var(--border)" }}
            tick={{ fill: "var(--muted-foreground)", fontSize: 11 }}
            minTickGap={16}
          />
          <YAxis
            orientation="right"
            tickLine={false}
            axisLine={false}
            width={48}
            tick={{ fill: "var(--muted-foreground)", fontSize: 11 }}
            tickFormatter={compact}
          />
          <Tooltip content={TrendTooltip} cursor={{ stroke: "var(--muted-foreground)", strokeWidth: 1 }} />
          <Line
            dataKey="previous"
            stroke="var(--muted-foreground)"
            strokeOpacity={0.7}
            strokeWidth={2}
            strokeDasharray="4 4"
            dot={false}
            activeDot={{ r: 4 }}
            connectNulls
            isAnimationActive={false}
          />
          <Line
            dataKey="net"
            stroke="var(--primary)"
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 4, stroke: "var(--background)", strokeWidth: 2 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </figure>
  );
}
