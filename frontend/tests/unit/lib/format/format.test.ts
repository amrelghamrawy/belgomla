import { describe, expect, it } from "vitest";

import { formatClock, formatDate, formatDateTime } from "@/lib/format/date";
import { formatMoney, formatPercent } from "@/lib/format/money";
import { phoneField } from "@/lib/forms/phone";
import { formatPhone, whatsappLink } from "@/lib/format/phone";

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

describe("formatClock", () => {
  it.each([
    ["09:00", "9:00 ص"],
    ["21:30", "9:30 م"],
    ["12:00", "12:00 م"],
    ["00:00", "12:00 ص"],
    ["24:00", "12:00 ص"],
  ])("shows the store hour %s as %s", (time, shown) => {
    expect(formatClock(time)).toBe(shown);
  });
});

describe("formatPercent", () => {
  it.each([
    ["25.00", "25"],
    ["12.50", "12.5"],
    ["7.25", "7.25"],
    ["10", "10"],
  ])("shows %s as %s", (percent, expected) => {
    expect(formatPercent(percent)).toBe(expected);
  });
});

describe("formatPhone", () => {
  it("shows an E.164 Egyptian number the local way", () => {
    expect(formatPhone("+201012345678")).toBe("01012345678");
  });

  it("leaves any other number as it is", () => {
    expect(formatPhone("+966501234567")).toBe("+966501234567");
  });
});

describe("whatsappLink", () => {
  it("links to a chat with the digits only", () => {
    expect(whatsappLink("+20 100 123 4567")).toBe("https://wa.me/201001234567");
  });
});

describe("phoneField", () => {
  it.each(["01012345678", "+201012345678", "010 1234 5678", "0101-234-5678"])("accepts %s", (phone) => {
    expect(phoneField().safeParse(phone).success).toBe(true);
  });

  it.each(["", "0101234567", "01312345678"])("rejects %j", (phone) => {
    expect(phoneField().safeParse(phone).success).toBe(false);
  });
});
