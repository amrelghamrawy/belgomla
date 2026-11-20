"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  type TooltipContentProps,
  XAxis,
  YAxis,
  type TooltipValueType,
} from "recharts";

import type { SalesReport } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

const copy = ar.dashboard.reports;

type Point = { label: string; net: number; returns: number; netMoney: string; returnsMoney: string; orders: number };

/** Compact axis ticks: 950 / 1.2K / 3.4M (Western digits, D39). */
export function compact(value: number): string {
  if (Math.abs(value) >= 1_000_000) {
    return `${Number((value / 1_000_000).toFixed(1))}M`;
  }

  if (Math.abs(value) >= 1_000) {
    return `${Number((value / 1_000).toFixed(1))}K`;
  }

  return String(value);
}

function SalesTooltip({ active, payload }: TooltipContentProps<TooltipValueType, string | number>) {
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
      {point.returns > 0 && (
        <p className="text-destructive">
          {copy.returns}: {formatMoney(point.returnsMoney)}
        </p>
      )}
      <p className="text-muted-foreground">
        {copy.orders}: {point.orders}
      </p>
    </div>
  );
}

/**
 * Net sales per period as columns in the brand color, with the period's returns (BR-R05) as a second, muted
 * series beside them and a two-entry legend; thin columns with a rounded cap on a recessive grid; a hover tooltip
 * per column; time runs right to left in RTL (the axis is reversed and the value axis sits on the right). The
 * table view carries the same numbers.
 */
export function SalesChart({ report, className = "h-72" }: { report: SalesReport; className?: string }) {
  const points: Point[] = report.buckets.map((bucket) => ({
    label: formatDate(bucket.period_start),
    net: Number(bucket.net),
    returns: Number(bucket.returns),
    netMoney: bucket.net,
    returnsMoney: bucket.returns,
    orders: bucket.orders,
  }));

  return (
    <figure
      aria-label={copy.chartLabel(formatDate(report.from), formatDate(report.to))}
      className={cn("flex w-full flex-col gap-1", className)}
    >
      <figcaption className="text-muted-foreground flex gap-4 text-xs">
        <span className="flex items-center gap-1">
          <span aria-hidden className="bg-primary inline-block size-2.5 rounded-sm" />
          {copy.net}
        </span>
        <span className="flex items-center gap-1">
          <span aria-hidden className="bg-destructive/60 inline-block size-2.5 rounded-sm" />
          {copy.returns}
        </span>
      </figcaption>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={points} margin={{ top: 8, right: 8, bottom: 8, left: 8 }} barCategoryGap={2}>
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
          <Tooltip content={SalesTooltip} cursor={{ fill: "var(--muted)", opacity: 0.6 }} />
          <Bar dataKey="net" fill="var(--primary)" maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false} />
          <Bar
            dataKey="returns"
            fill="var(--destructive)"
            fillOpacity={0.6}
            maxBarSize={24}
            radius={[4, 4, 0, 0]}
            isAnimationActive={false}
          />
        </BarChart>
      </ResponsiveContainer>
    </figure>
  );
}
