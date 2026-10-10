import { describe, expect, it } from "vitest";

import { ar } from "@/lib/copy/ar";
import {
  percentFromPrice,
  priceFromPercent,
  type TierDraft,
  tierProblems,
  tiersBody,
  toBasisPoints,
  toPiasters,
} from "@/lib/dashboard/price-math";

const messages = ar.dashboard.productForm.tierErrors;

describe("priceFromPercent", () => {
  it("D27 rounds half-up to the piaster: 120.00 at 25% is 90.00, 10.10 at 25% is 7.58", () => {
    expect(priceFromPercent("120.00", "25")).toBe("90.00");
    expect(priceFromPercent("10.10", "25")).toBe("7.58");
    expect(priceFromPercent("99.99", "12.5")).toBe("87.49");
  });

  it("stays exact for the largest cover price the backend accepts", () => {
    expect(priceFromPercent("9999999999.99", "50")).toBe("5000000000.00");
  });

  it("returns null for a missing cover price or a percentage outside 0 < p < 100", () => {
    expect(priceFromPercent("", "25")).toBeNull();
    expect(priceFromPercent("120.00", "0")).toBeNull();
    expect(priceFromPercent("120.00", "100")).toBeNull();
    expect(priceFromPercent("120.00", "abc")).toBeNull();
  });
});

describe("percentFromPrice", () => {
  it("shows the discount a price implies off the cover price", () => {
    expect(percentFromPrice("120.00", "90.00")).toBe("25.00");
    expect(percentFromPrice("30.00", "20.00")).toBe("33.33");
  });

  it("has no discount for a price at or above the cover price", () => {
    expect(percentFromPrice("120.00", "120.00")).toBeNull();
    expect(percentFromPrice("120.00", "150")).toBeNull();
  });
});

describe("money and percent parsing", () => {
  it("reads money with up to two decimals and percentages strictly between 0 and 100", () => {
    expect(toPiasters("120.5")).toBe(12050n);
    expect(toPiasters("1.234")).toBeNull();
    expect(toBasisPoints("12.5")).toBe(1250n);
    expect(toBasisPoints("100")).toBeNull();
  });
});

describe("tierProblems", () => {
  const tier = (min: string, value: string, mode: TierDraft["mode"] = "price"): TierDraft => ({
    min_quantity: min,
    mode,
    value,
  });

  it("BR-PR04 flags a threshold used twice, like the backend's invalid_tiers", () => {
    expect(
      tierProblems([tier("10", "85"), tier("10", "80")], { cover: "120.00", basePrice: "90.00" }, messages),
    ).toEqual({
      1: "الحد الأدنى 10 مكرر في أكثر من شريحة",
    });
  });

  it("flags a tier priced above the base price, including one entered as a percentage", () => {
    expect(
      tierProblems([tier("10", "95"), tier("20", "10", "percent")], { cover: "120.00", basePrice: "90.00" }, messages),
    ).toEqual({ 0: "سعر شريحة 10 أعلى من السعر الأساسي", 1: "سعر شريحة 20 أعلى من السعر الأساسي" });
  });

  it("BR-PR10 needs a cover price for a percentage tier, and valid numbers everywhere", () => {
    expect(
      tierProblems(
        [tier("10", "20", "percent"), tier("0", "80"), tier("30", "x")],
        { cover: null, basePrice: "90.00" },
        messages,
      ),
    ).toEqual({ 0: messages.percentNeedsCover, 1: messages.invalidQuantity, 2: messages.invalidValue });
  });

  it("accepts valid ascending tiers", () => {
    expect(
      tierProblems([tier("10", "85"), tier("50", "30", "percent")], { cover: "120.00", basePrice: "90.00" }, messages),
    ).toEqual({});
  });
});

describe("tiersBody", () => {
  it("sends unit_price or discount_percent, never both", () => {
    expect(
      tiersBody([
        { min_quantity: "10", mode: "price", value: "85.00" },
        { min_quantity: "50", mode: "percent", value: "30" },
      ]),
    ).toEqual([
      { min_quantity: 10, unit_price: "85.00" },
      { min_quantity: 50, discount_percent: "30" },
    ]);
  });
});
