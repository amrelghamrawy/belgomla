"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  type TooltipContentProps,
  type TooltipValueType,
  XAxis,
  YAxis,
} from "recharts";

import type { Money } from "@/lib/api/types";
import { formatDay } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

import { compact } from "./SalesChart";

export type PeriodPoint = { period_start: string; value: Money };

type Point = { label: string; value: number; money: Money };

function PeriodTooltip({ active, payload }: TooltipContentProps<TooltipValueType, string | number>) {
  const point = payload?.[0]?.payload as Point | undefined;

  if (!active || point === undefined) {
    return null;
  }

  return (
    <div className="bg-popover text-popover-foreground rounded-xl px-3 py-2 text-xs shadow-lg">
      <p className="text-muted-foreground">{point.label}</p>
      <p className="font-semibold">{formatMoney(point.money)}</p>
    </div>
  );
}

/**
 * D104: one money series per period as brand columns (one series, so the card title names it and no legend box is
 * drawn): thin columns with a rounded cap from one baseline, a recessive grid, compact ticks, a tooltip per column;
 * time runs right to left in RTL. The table under it carries the same numbers.
 */
export function PeriodColumns({ points, label }: { points: PeriodPoint[]; label: string }) {
  const data: Point[] = points.map((point) => ({
    label: formatDay(point.period_start),
    value: Number(point.value),
    money: point.value,
  }));

  return (
    <figure aria-label={label} className="h-60 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 8 }} barCategoryGap="20%">
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
            width={44}
            tick={{ fill: "var(--muted-foreground)", fontSize: 11 }}
            tickFormatter={compact}
          />
          <Tooltip content={PeriodTooltip} cursor={{ fill: "var(--muted)", opacity: 0.7 }} />
          <Bar dataKey="value" fill="var(--primary)" maxBarSize={22} radius={[6, 6, 0, 0]} isAnimationActive={false} />
        </BarChart>
      </ResponsiveContainer>
    </figure>
  );
}
