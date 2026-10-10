import { describe, expect, it } from "vitest";

import { formatDate, formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

describe("formatMoney", () => {
  it.each([
    ["150.00", "150.00 ج.م"],
    ["0.00", "0.00 ج.م"],
    ["1250.50", "1,250.50 ج.م"],
    ["1000000.00", "1,000,000.00 ج.م"],
    ["-500.25", "-500.25 ج.م"],
  ])("D39 formats %s with Western digits as %s", (amount, expected) => {
    expect(formatMoney(amount)).toBe(expected);
  });

  it.each(["150", "150.5", "1,250.00", "abc", ""])("rejects %j, which is not a money string", (amount) => {
    expect(() => formatMoney(amount)).toThrow(RangeError);
  });
});

describe("formatDate", () => {
  it("shows the Cairo date, one hour later in summer time", () => {
    expect(formatDate("2026-07-01T21:30:00Z")).toBe("2 يوليو 2026");
  });

  it("shows the Cairo date in winter (UTC+2)", () => {
    expect(formatDate("2026-01-15T22:30:00Z")).toBe("16 يناير 2026");
  });

  it("rejects an invalid date", () => {
    expect(() => formatDate("yesterday")).toThrow(RangeError);
  });
});

describe("formatDateTime", () => {
  it("shows the Cairo time with Arabic day period", () => {
    expect(formatDateTime("2026-01-15T10:05:00Z")).toBe("15 يناير 2026 في 12:05 م");
  });
});
