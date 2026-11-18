import type { ReportAging, ReportName } from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Pure helpers of the advanced reports (V2 Task 16, D94): the date range presets, the change against the previous
// period, the comparison line, the params each report understands and the aging bar's segments.

/** A calendar day as the API takes it: "2026-10-29". */
export type Day = string;

export type RangePreset = "week" | "month" | "season";

export const RANGE_PRESETS: readonly RangePreset[] = ["week", "month", "season"];

const cairoDay = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Africa/Cairo",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

/** Today in Cairo (D60), whatever the browser's time zone. */
export function cairoToday(now: Date = new Date()): Day {
  return cairoDay.format(now);
}

function parts(day: Day): [number, number, number] {
  const [year = 0, month = 1, date = 1] = day.split("-").map(Number);

  return [year, month, date];
}

const pad = (value: number) => String(value).padStart(2, "0");

/**
 * The range of a preset, ending today: this week from the Saturday on or before today (D58), this month from the
 * 1st, this season from 1 August of the current school year (before August, the previous year's).
 */
export function presetRange(preset: RangePreset, today: Day): { from: Day; to: Day } {
  const [year, month, date] = parts(today);

  if (preset === "week") {
    const day = new Date(Date.UTC(year, month - 1, date));
    // getUTCDay: Saturday = 6, so Saturday goes back 0 days, Sunday 1, … Friday 6.
    day.setUTCDate(day.getUTCDate() - ((day.getUTCDay() + 1) % 7));

    return { from: day.toISOString().slice(0, 10), to: today };
  }

  if (preset === "month") {
    return { from: `${year}-${pad(month)}-01`, to: today };
  }

  return { from: `${month >= 8 ? year : year - 1}-08-01`, to: today };
}

/** Which preset the range is, if any (the pressed button). */
export function activePreset(from: Day | undefined, to: Day | undefined, today: Day): RangePreset | null {
  return (
    RANGE_PRESETS.find((preset) => {
      const range = presetRange(preset, today);

      return range.from === from && range.to === to;
    }) ?? null
  );
}

/** The change from `previous` to `current` in %, one decimal; null when there was nothing before to compare. */
export function deltaPercent(current: string | number | null, previous: string | number | null): number | null {
  if (current === null || previous === null) {
    return null;
  }

  const before = Number(previous);

  if (before === 0) {
    return null;
  }

  return Math.round(((Number(current) - before) / Math.abs(before)) * 1000) / 10;
}

/** "+12.5%", "−3%", "0%" (Western digits, D39). */
export function formatDelta(delta: number): string {
  if (delta === 0) {
    return "0%";
  }

  return `${delta > 0 ? "+" : "−"}${Math.abs(delta)}%`;
}

export type TrendPoint = {
  periodStart: Day;
  net: number;
  netMoney: string;
  /** The previous period's bucket at the same position; null when the previous period has fewer buckets. */
  previous: number | null;
  previousMoney: string | null;
  previousStart: Day | null;
};

/** The current buckets with the previous period's net beside them, aligned by position (the API lists both alike). */
export function alignPrevious(
  buckets: readonly { period_start: Day; net: string }[],
  previous: readonly { period_start: Day; net: string }[],
): TrendPoint[] {
  return buckets.map((bucket, index) => {
    const before = previous[index];

    return {
      periodStart: bucket.period_start,
      net: Number(bucket.net),
      netMoney: bucket.net,
      previous: before === undefined ? null : Number(before.net),
      previousMoney: before?.net ?? null,
      previousStart: before?.period_start ?? null,
    };
  });
}

/** The URL keys each report reads (a filter that does not apply to a report is not sent, D94 f). */
export const REPORT_KEYS: Record<ReportName, readonly string[]> = {
  "sales-analytics": ["from", "to", "group", "category_id", "customer_group_id"],
  products: ["from", "to", "category_id", "customer_group_id", "sort"],
  customers: ["from", "to", "customer_group_id", "view", "dormant_days"],
  inventory: ["from", "to", "category_id", "dead_days", "dead_only"],
  collections: ["from", "to", "group", "customer_group_id"],
  "profit-and-loss": ["from", "to"], // D100 j: the range only
};

/** Every key the reports page keeps in the URL. */
export const REPORT_QUERY_KEYS: readonly string[] = [
  "tab",
  ...Array.from(new Set(Object.values(REPORT_KEYS).flat())),
  "low_page",
  "debt_page",
];

/** The params of one report from the page's URL query (paging is added by the caller). */
export function reportParams(report: ReportName, query: ListQuery): Record<string, string> {
  const params: Record<string, string> = {};

  for (const key of REPORT_KEYS[report]) {
    const value = query[key];

    if (value !== undefined && value !== "") {
      params[key] = value;
    }
  }

  return params;
}

/** BR-C06: the aging buckets, from not yet due to the oldest. */
export const AGING_BUCKETS = ["current", "0_30", "31_60", "61_90", "over_90"] as const;

export type AgingBucket = (typeof AGING_BUCKETS)[number];

/** The aging as segments of one bar: each bucket's share of their sum in %, the empty ones left out. */
export function agingSegments(aging: ReportAging): { key: AgingBucket; amount: string; percent: number }[] {
  const total = AGING_BUCKETS.reduce((sum, key) => sum + Number(aging[key]), 0);

  if (total <= 0) {
    return [];
  }

  return AGING_BUCKETS.filter((key) => Number(aging[key]) > 0).map((key) => ({
    key,
    amount: aging[key],
    percent: (Number(aging[key]) / total) * 100,
  }));
}

/** A whole-number option (days) typed in an input; empty or invalid → null (the report's default). */
export function daysValue(value: string): string | null {
  return /^[1-9]\d{0,3}$/.test(value) ? value : null;
}
