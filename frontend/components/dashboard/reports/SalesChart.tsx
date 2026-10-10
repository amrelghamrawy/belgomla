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

const copy = ar.dashboard.reports;

type Point = { label: string; total: number; money: string; orders: number };

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
        {copy.sales}: {formatMoney(point.money)}
      </p>
      <p className="text-muted-foreground">
        {copy.orders}: {point.orders}
      </p>
    </div>
  );
}

/**
 * Sales per period as columns: one series in the brand color, so no legend (the title names it); thin
 * columns with a rounded cap on a recessive grid; a hover tooltip per column; time runs right to left in
 * RTL (the axis is reversed and the value axis sits on the right). The table view carries the same numbers.
 */
export function SalesChart({ report }: { report: SalesReport }) {
  const points: Point[] = report.buckets.map((bucket) => ({
    label: formatDate(bucket.period_start),
    total: Number(bucket.total),
    money: bucket.total,
    orders: bucket.orders,
  }));

  return (
    <figure aria-label={copy.chartLabel(formatDate(report.from), formatDate(report.to))} className="h-72 w-full">
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
          <Bar dataKey="total" fill="var(--primary)" maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false} />
        </BarChart>
      </ResponsiveContainer>
    </figure>
  );
}
