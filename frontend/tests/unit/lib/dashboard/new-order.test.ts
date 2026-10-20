import { describe, expect, it } from "vitest";

import {
  addLine,
  type DraftLine,
  quoteBody,
  quotedLine,
  quotePasses,
  removeLine,
  setQuantity,
} from "@/lib/dashboard/new-order";

import { orderQuote as quote, orderQuoteLine as quoteLine } from "../../dashboard-fixtures";

const line = (overrides: Partial<DraftLine> = {}): DraftLine => ({
  sku_id: 3,
  quantity: 5,
  name: "الأضواء علوم",
  code: "ADW-SCI",
  ...overrides,
});

describe("draft lines (BR-O11)", () => {
  it("adds a product once", () => {
    const lines = addLine([line()], line({ quantity: 9 }));

    expect(lines).toEqual([line()]);
    expect(addLine(lines, line({ sku_id: 4 }))).toHaveLength(2);
  });

  it("sets a quantity; anything but a whole number of at least 1 is an empty quantity (0)", () => {
    expect(setQuantity([line()], 3, 12)[0]?.quantity).toBe(12);
    expect(setQuantity([line()], 3, 0)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 3, 2.5)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 3, Number.NaN)[0]?.quantity).toBe(0);
    expect(setQuantity([line()], 9, 12)).toEqual([line()]);
  });

  it("removes a line", () => {
    expect(removeLine([line(), line({ sku_id: 4 })], 3)).toEqual([line({ sku_id: 4 })]);
  });

  it("quotes only with a customer and at least one line, sending ids and quantities", () => {
    expect(quoteBody(null, [line()])).toBeNull();
    expect(quoteBody(7, [])).toBeNull();
    expect(quoteBody(7, [line(), line({ sku_id: 4, quantity: 0 })])).toBeNull();
    expect(quoteBody(7, [line()])).toEqual({ customer_id: 7, lines: [{ sku_id: 3, quantity: 5 }] });
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
