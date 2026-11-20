import { describe, expect, it } from "vitest";

import {
  activePreset,
  agingSegments,
  alignPrevious,
  cairoToday,
  daysValue,
  deltaPercent,
  formatDelta,
  presetRange,
  REPORT_QUERY_KEYS,
  reportParams,
} from "@/lib/dashboard/report-range";

const aging = (overrides: Record<string, string> = {}) => ({
  current: "0.00",
  "0_30": "0.00",
  "31_60": "0.00",
  "61_90": "0.00",
  over_90: "0.00",
  overdue: "0.00",
  outstanding: "0.00",
  ...overrides,
});

describe("cairoToday", () => {
  it("D60 takes the day in Cairo, not in UTC", () => {
    expect(cairoToday(new Date("2026-07-01T21:30:00Z"))).toBe("2026-07-02");
    expect(cairoToday(new Date("2026-01-15T21:59:00Z"))).toBe("2026-01-15");
    expect(cairoToday(new Date("2026-01-15T22:00:00Z"))).toBe("2026-01-16");
  });
});

describe("presetRange", () => {
  it("D58 starts this week on the Saturday on or before today", () => {
    expect(presetRange("week", "2026-10-29")).toEqual({ from: "2026-10-24", to: "2026-10-29" });
    expect(presetRange("week", "2026-10-24")).toEqual({ from: "2026-10-24", to: "2026-10-24" });
    expect(presetRange("week", "2026-10-25")).toEqual({ from: "2026-10-24", to: "2026-10-25" });
    expect(presetRange("week", "2026-10-30")).toEqual({ from: "2026-10-24", to: "2026-10-30" });
  });

  it("goes back across a year for a week that started in December", () => {
    expect(presetRange("week", "2026-01-02")).toEqual({ from: "2025-12-27", to: "2026-01-02" });
  });

  it("starts this month on the 1st", () => {
    expect(presetRange("month", "2026-10-29")).toEqual({ from: "2026-10-01", to: "2026-10-29" });
    expect(presetRange("month", "2026-02-01")).toEqual({ from: "2026-02-01", to: "2026-02-01" });
  });

  it("starts this season on 1 August of this year from August on", () => {
    expect(presetRange("season", "2026-10-29")).toEqual({ from: "2026-08-01", to: "2026-10-29" });
    expect(presetRange("season", "2026-08-01")).toEqual({ from: "2026-08-01", to: "2026-08-01" });
  });

  it("starts this season on 1 August of last year before August", () => {
    expect(presetRange("season", "2026-07-31")).toEqual({ from: "2025-08-01", to: "2026-07-31" });
    expect(presetRange("season", "2026-01-10")).toEqual({ from: "2025-08-01", to: "2026-01-10" });
  });
});

describe("activePreset", () => {
  it("names the preset the range matches, or none", () => {
    expect(activePreset("2026-10-24", "2026-10-29", "2026-10-29")).toBe("week");
    expect(activePreset("2026-10-01", "2026-10-29", "2026-10-29")).toBe("month");
    expect(activePreset("2026-08-01", "2026-10-29", "2026-10-29")).toBe("season");
    expect(activePreset("2026-10-01", "2026-10-28", "2026-10-29")).toBeNull();
    expect(activePreset(undefined, undefined, "2026-10-29")).toBeNull();
  });
});

describe("deltaPercent / formatDelta", () => {
  it("is the change in % with one decimal", () => {
    expect(deltaPercent("1150.00", "1000.00")).toBe(15);
    expect(deltaPercent(2, 3)).toBe(-33.3);
    expect(deltaPercent("100.00", "100.00")).toBe(0);
  });

  it("measures against the size of a negative previous value", () => {
    expect(deltaPercent("50.00", "-100.00")).toBe(150);
  });

  it("has nothing to compare when the previous value is zero or missing", () => {
    expect(deltaPercent("500.00", "0.00")).toBeNull();
    expect(deltaPercent(null, "10.00")).toBeNull();
    expect(deltaPercent("10.00", null)).toBeNull();
  });

  it("shows the sign, a minus for a fall and no sign for no change", () => {
    expect(formatDelta(12.5)).toBe("+12.5%");
    expect(formatDelta(-3)).toBe("−3%");
    expect(formatDelta(0)).toBe("0%");
  });
});

describe("alignPrevious", () => {
  it("puts the previous period's bucket at the same position beside each current one", () => {
    const points = alignPrevious(
      [
        { period_start: "2026-10-01", net: "100.00" },
        { period_start: "2026-10-02", net: "-20.50" },
      ],
      [{ period_start: "2026-09-29", net: "80.00" }],
    );

    expect(points).toEqual([
      {
        periodStart: "2026-10-01",
        net: 100,
        netMoney: "100.00",
        previous: 80,
        previousMoney: "80.00",
        previousStart: "2026-09-29",
      },
      {
        periodStart: "2026-10-02",
        net: -20.5,
        netMoney: "-20.50",
        previous: null,
        previousMoney: null,
        previousStart: null,
      },
    ]);
  });
});

describe("reportParams", () => {
  const query = {
    tab: "sales",
    from: "2026-10-01",
    to: "2026-10-29",
    group: "week",
    category_id: "4",
    customer_group_id: "2",
    sort: "quantity",
    view: "dormant",
    dormant_days: "30",
    dead_days: "90",
    dead_only: "1",
    page: "2",
  };

  it("D94 f sends each report only the filters and options it reads", () => {
    expect(reportParams("sales-analytics", query)).toEqual({
      from: "2026-10-01",
      to: "2026-10-29",
      group: "week",
      category_id: "4",
      customer_group_id: "2",
    });
    expect(reportParams("products", query)).toEqual({
      from: "2026-10-01",
      to: "2026-10-29",
      category_id: "4",
      customer_group_id: "2",
      sort: "quantity",
    });
    expect(reportParams("customers", query)).toEqual({
      from: "2026-10-01",
      to: "2026-10-29",
      customer_group_id: "2",
      view: "dormant",
      dormant_days: "30",
    });
    expect(reportParams("inventory", query)).toEqual({
      from: "2026-10-01",
      to: "2026-10-29",
      category_id: "4",
      dead_days: "90",
      dead_only: "1",
    });
    expect(reportParams("collections", query)).toEqual({
      from: "2026-10-01",
      to: "2026-10-29",
      group: "week",
      customer_group_id: "2",
    });
  });

  it("leaves out what is not set", () => {
    expect(reportParams("collections", { from: "", tab: "collections" })).toEqual({});
  });

  it("keeps every report key, the tab and the side lists' pages in the URL once", () => {
    expect(REPORT_QUERY_KEYS).toContain("tab");
    expect(REPORT_QUERY_KEYS).toContain("dead_only");
    expect(REPORT_QUERY_KEYS).toContain("low_page");
    expect(REPORT_QUERY_KEYS).toContain("debt_page");
    expect(new Set(REPORT_QUERY_KEYS).size).toBe(REPORT_QUERY_KEYS.length);
  });
});

describe("agingSegments", () => {
  it("BR-C06 gives each non-empty bucket its share of the bar, from not yet due to the oldest", () => {
    expect(agingSegments(aging({ current: "300.00", "31_60": "100.00", over_90: "600.00" }))).toEqual([
      { key: "current", amount: "300.00", percent: 30 },
      { key: "31_60", amount: "100.00", percent: 10 },
      { key: "over_90", amount: "600.00", percent: 60 },
    ]);
  });

  it("has no segments without debts", () => {
    expect(agingSegments(aging())).toEqual([]);
  });
});

describe("daysValue", () => {
  it("takes a positive whole number of days and drops anything else", () => {
    expect(daysValue("45")).toBe("45");
    expect(daysValue("")).toBeNull();
    expect(daysValue("0")).toBeNull();
    expect(daysValue("-3")).toBeNull();
    expect(daysValue("1.5")).toBeNull();
  });
});
