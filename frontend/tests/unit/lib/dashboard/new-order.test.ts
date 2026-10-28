import { describe, expect, it } from "vitest";

import {
  addLine,
  discountedQuote,
  lineFromProduct,
  type DraftLine,
  orderLinesBody,
  quoteBody,
  quotedLine,
  quotePasses,
  removeLine,
  setQuantity,
  setUnit,
} from "@/lib/dashboard/new-order";

import { NO_DISCOUNT } from "@/lib/dashboard/price-math";

import {
  dashboardProduct,
  dashboardSku,
  dashboardSkuUnit,
  orderQuote as quote,
  orderQuoteLine as quoteLine,
} from "../../dashboard-fixtures";

const line = (overrides: Partial<DraftLine> = {}): DraftLine => ({
  sku_unit_id: 3,
  quantity: 5,
  name: "الأضواء علوم",
  code: "ADW-SCI",
  ...overrides,
});

describe("draft lines (BR-O11)", () => {
  it("adds a product once", () => {
    const lines = addLine([line()], line({ quantity: 9 }));

    expect(lines).toEqual([line()]);
    expect(addLine(lines, line({ sku_unit_id: 4 }))).toHaveLength(2);
  });

  it("sets a quantity; anything but a whole number of at least 1 is an empty quantity (0)", () => {
    expect(setQuantity([line()], 3, 12)[0]?.quantity).toBe(12);
    expect(setQuantity([line()], 3, 0)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 3, 2.5)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 3, Number.NaN)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 9, 12)).toEqual([line()]);
  });

  it("D91 makes a picked product a line in its default unit, with the SKU's units to switch between", () => {
    const sku = dashboardSku({ id: 3, code: "ADW-SCI", moq: 5 });
    const product = dashboardProduct({
      name: "الأضواء علوم",
      sku: {
        ...sku,
        units: [
          dashboardSkuUnit({ id: 9, moq: 2, is_default: true }),
          ...(sku.units ?? []).map((unit) => ({ ...unit, is_default: false })),
        ],
      },
    });

    expect(lineFromProduct(product)).toEqual({
      sku_unit_id: 9,
      quantity: 2,
      name: "الأضواء علوم",
      code: "ADW-SCI",
      units: [
        { id: 9, name: "كرتونة 12", moq: 2 },
        { id: 3, name: "كرتونة", moq: 5 },
      ],
    });
    expect(lineFromProduct(dashboardProduct({ sku: null }))).toBeNull();
  });

  it("D91 moves a line to another unit at its MOQ, unless that unit is already a line", () => {
    const units = [
      { id: 3, name: "قطعة", moq: 5 },
      { id: 9, name: "كرتونة", moq: 1 },
    ];

    expect(setUnit([line({ units })], 3, 9)).toEqual([line({ units, sku_unit_id: 9, quantity: 1 })]);
    expect(setUnit([line({ units }), line({ units, sku_unit_id: 9 })], 3, 9)).toHaveLength(2);
    expect(setUnit([line({ units })], 3, 42)).toEqual([line({ units })]);
  });

  it("removes a line", () => {
    expect(removeLine([line(), line({ sku_unit_id: 4 })], 3)).toEqual([line({ sku_unit_id: 4 })]);
  });

  it("quotes only with a customer and at least one line, sending ids and quantities", () => {
    expect(quoteBody(null, [line()])).toBeNull();
    expect(quoteBody(7, [])).toBeNull();
    expect(quoteBody(7, [line(), line({ sku_unit_id: 4, quantity: 0 })])).toBeNull();
    expect(quoteBody(7, [line()])).toEqual({ customer_id: 7, lines: [{ sku_unit_id: 3, quantity: 5 }] });
  });
});

describe("quotePasses (BR-PR06, D84)", () => {
  it("passes a clean quote", () => {
    expect(quotePasses(quote(), false)).toBe(true);
  });

  it("fails on any line problem, even with the override", () => {
    const failing = quote({ lines: [quoteLine({ problem: { reason: "below_moq", moq: 5 } })] });

    expect(quotePasses(failing, true)).toBe(false);
  });

  it("fails past the credit limit, even with the override", () => {
    const failing = quote({ credit: { limit: "100.00", exposure: "0.00", order_total: "1020.00" } });

    expect(quotePasses(failing, true)).toBe(false);
  });

  it("fails under the minimum order value unless it is waived", () => {
    const short = quote({ mov: { enabled: true, amount: "1500.00", satisfied: false } });

    expect(quotePasses(short, false)).toBe(false);
    expect(quotePasses(short, true)).toBe(true);
  });

  it("finds the quoted line of a sku", () => {
    expect(quotedLine(quote(), 3)?.unit_price).toBe("85.00");
    expect(quotedLine(quote(), 4)).toBeUndefined();
    expect(quotedLine(undefined, 3)).toBeUndefined();
  });
});

describe("discounts on the form (D85)", () => {
  const lines = [
    { sku_unit_id: 3, quantity: 12, name: "الأضواء علوم", code: "ADW-SCI" },
    { sku_unit_id: 4, quantity: 5, name: "سلاح التلميذ", code: "SLH" },
  ];

  it("sends a line's discount only when it has one", () => {
    expect(orderLinesBody(lines, { 3: { type: "percent", value: " 10 " }, 4: { type: "amount", value: "" } })).toEqual([
      { sku_unit_id: 3, quantity: 12, discount: { type: "percent", value: "10" } },
      { sku_unit_id: 4, quantity: 5 },
    ]);
  });

  it("prices the discounts from the quoted prices, and has nothing to show before every line is quoted", () => {
    const quoted = quote({
      lines: [quoteLine(), quoteLine({ sku_unit_id: 4, quantity: 5, unit_price: "20.00", line_total: "100.00" })],
    });

    expect(
      discountedQuote(quoted, lines, { 3: { type: "percent", value: "10" } }, { type: "amount", value: "18" }),
    ).toMatchObject({
      items_total: "1018.00",
      discount_amount: "18.00",
      order_total: "1000.00",
    });
    expect(discountedQuote(quote(), lines, {}, NO_DISCOUNT)).toBeNull();
    expect(discountedQuote(undefined, lines, {}, NO_DISCOUNT)).toBeNull();
  });
});
