import { describe, expect, it } from "vitest";

import { canOrder, clampQuantity, movProgress, quantityBounds, unitSummary } from "@/lib/store/quantity";

describe("quantityBounds", () => {
  it("BR-MOQ01 starts at the MOQ and stops at what the stock covers in selling units (D37)", () => {
    expect(quantityBounds({ moq: 2, conversion_factor: 20, available: 410 })).toEqual({ min: 2, max: 20 });
  });

  it("D21 a coming-soon product (no available) cannot be ordered", () => {
    const bounds = quantityBounds({ moq: 1, conversion_factor: 1 });

    expect(bounds).toEqual({ min: 1, max: 0 });
    expect(canOrder(bounds)).toBe(false);
  });

  it("cannot be ordered when the stock is below the MOQ", () => {
    expect(canOrder(quantityBounds({ moq: 5, conversion_factor: 20, available: 80 }))).toBe(false);
  });

  it("can be ordered when the stock covers exactly the MOQ", () => {
    expect(canOrder(quantityBounds({ moq: 5, conversion_factor: 20, available: 100 }))).toBe(true);
  });
});

describe("clampQuantity", () => {
  const bounds = { min: 2, max: 20 };

  it.each([
    ["below the MOQ", 1, 2],
    ["above the stock", 25, 20],
    ["inside the bounds", 7, 7],
    ["a fraction", 7.8, 7],
    ["not a number", Number.NaN, 2],
  ])("keeps a quantity %s inside the bounds", (_, quantity, expected) => {
    expect(clampQuantity(quantity, bounds)).toBe(expected);
  });
});

describe("movProgress", () => {
  it("BR-MOV02 shows what is left to the minimum order value", () => {
    expect(movProgress({ items_total: "700.00", mov: { enabled: true, amount: "1000.00", satisfied: false } })).toEqual(
      {
        remaining: "300.00",
        percent: 70,
        satisfied: false,
      },
    );
  });

  it("is complete once the total reaches the minimum", () => {
    expect(movProgress({ items_total: "1050.50", mov: { enabled: true, amount: "1000.00", satisfied: true } })).toEqual(
      {
        remaining: "0.00",
        percent: 100,
        satisfied: true,
      },
    );
  });

  it("keeps the piasters", () => {
    expect(
      movProgress({ items_total: "499.75", mov: { enabled: true, amount: "500.00", satisfied: false } })?.remaining,
    ).toBe("0.25");
  });

  it.each([
    ["the minimum is off", { enabled: false, amount: "500.00", satisfied: true }],
    ["there is no amount", { enabled: true, amount: null, satisfied: true }],
  ])("is null when %s", (_, mov) => {
    expect(movProgress({ items_total: "100.00", mov })).toBeNull();
  });
});

describe("unitSummary", () => {
  it.each([
    ["a pack with an MOQ", { unit: "كرتونة", conversion_factor: 20, moq: 2 }, "كرتونة = 20 نسخة · أقل طلب 2"],
    ["a pack without an MOQ", { unit: "كرتونة", conversion_factor: 20, moq: 1 }, "كرتونة = 20 نسخة"],
    ["a loose unit with an MOQ", { unit: "نسخة", conversion_factor: 1, moq: 3 }, "أقل طلب 3 نسخة"],
    ["a loose unit", { unit: "نسخة", conversion_factor: 1, moq: 1 }, "السعر لكل نسخة"],
    ["no unit name", { unit: null, conversion_factor: 1, moq: 1 }, ""],
  ])("summarises %s", (_case, sku, expected) => {
    expect(unitSummary(sku)).toBe(expected);
  });
});
