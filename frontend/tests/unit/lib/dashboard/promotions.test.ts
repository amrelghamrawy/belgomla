import { describe, expect, it } from "vitest";

import { promotionSchema } from "@/components/dashboard/pricing/PromotionDialog";
import { cairoInputValue, isCancellable, isEditable } from "@/lib/dashboard/promotions";

describe("cairoInputValue (D60)", () => {
  it("shows a UTC instant as Cairo wall time for a datetime-local input, winter and summer", () => {
    expect(cairoInputValue("2026-10-31T22:00:00+00:00")).toBe("2026-11-01T00:00");
    expect(cairoInputValue("2026-07-01T21:30:00Z")).toBe("2026-07-02T00:30");
  });
});

describe("what a promotion allows (D90 f)", () => {
  it("edits only a draft or a scheduled one, cancels anything not over", () => {
    expect([isEditable("draft"), isEditable("scheduled"), isEditable("active"), isEditable("ended")]).toEqual([
      true,
      true,
      false,
      false,
    ]);
    expect([
      isCancellable("active"),
      isCancellable("draft"),
      isCancellable("ended"),
      isCancellable("cancelled"),
    ]).toEqual([true, true, false, false]);
  });
});

describe("promotionSchema", () => {
  const valid = {
    name: "عرض الأضواء",
    discount_percent: "15",
    starts_at: "2026-11-01T00:00",
    ends_at: "2026-11-30T23:59",
    draft: false,
  };

  it("accepts a name, a % above 0 and below 90 and a window", () => {
    expect(promotionSchema.safeParse(valid).success).toBe(true);
  });

  it.each([
    ["no name", { name: " " }, "name"],
    ["0%", { discount_percent: "0" }, "discount_percent"],
    ["90%", { discount_percent: "90" }, "discount_percent"],
    ["3 decimals", { discount_percent: "10.125" }, "discount_percent"],
    ["no start", { starts_at: "" }, "starts_at"],
    ["end before start", { ends_at: "2026-10-30T00:00" }, "ends_at"],
  ])("refuses %s", (_, change, field) => {
    const result = promotionSchema.safeParse({ ...valid, ...change });

    expect(result.success).toBe(false);
    expect(result.error?.issues.map((issue) => issue.path[0])).toContain(field);
  });
});
