import { describe, expect, it } from "vitest";

import type { OrderItem } from "@/lib/api/types";
import {
  clampDelivered,
  deliveredBody,
  deliveredItemsTotal,
  editBody,
  editedItemsTotal,
  editHasProblems,
  editLinesOf,
  editQuoteBody,
  lineChanges,
} from "@/lib/dashboard/edit-order";

import { orderQuote, orderQuoteLine } from "../../dashboard-fixtures";

function item(overrides: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 61,
    sku_id: 5,
    sku_unit_id: 5,
    product_name: "أ",
    sku_code: "A-1",
    sku_name: "أ",
    unit: "كرتونة",
    conversion_factor: 20,
    quantity: 6,
    cover_price: null,
    list_unit_price: "90.00",
    manual_discount: null,
    unit_price: "90.00",
    discount_percent: null,
    tier_min_quantity: null,
    price_source: "base",
    line_total: "540.00",
    delivered_quantity: null,
    removed_at: null,
    converted_quantity: 0,
    ...overrides,
  };
}

// 6 cartons of A at 90.00 and 10 of B at 85.00; a removed line C.
const order = {
  items: [
    item(),
    item({
      id: 62,
      sku_unit_id: 6,
      product_name: "ب",
      sku_code: "B-1",
      quantity: 10,
      unit_price: "85.00",
      line_total: "850.00",
    }),
    item({ id: 63, sku_unit_id: 7, product_name: "ج", sku_code: "C-1", removed_at: "2026-10-20T08:00:00Z" }),
  ],
};

describe("order edit (BR-O12)", () => {
  it("starts from the live lines only", () => {
    expect(editLinesOf(order)).toEqual([
      { order_item_id: 61, sku_unit_id: 5, quantity: 6, name: "أ", code: "A-1" },
      { order_item_id: 62, sku_unit_id: 6, quantity: 10, name: "ب", code: "B-1" },
    ]);
  });

  it("lists nothing to change while the lines are the order's", () => {
    expect(lineChanges(order, editLinesOf(order))).toEqual([]);
  });

  it("lists the removed lines first, then the changed and added ones", () => {
    const lines = [
      { order_item_id: 61, sku_unit_id: 5, quantity: 12, name: "أ", code: "A-1" },
      { order_item_id: null, sku_unit_id: 8, quantity: 5, name: "د", code: "D-1" },
    ];

    expect(lineChanges(order, lines)).toEqual([
      { kind: "removed", sku_unit_id: 6, name: "ب", from: 10, to: null },
      { kind: "quantity", sku_unit_id: 5, name: "أ", from: 6, to: 12 },
      { kind: "added", sku_unit_id: 8, name: "د", from: null, to: 5 },
    ]);
  });

  it("quotes the edit with the order, and not while a quantity is empty", () => {
    const lines = editLinesOf(order);

    expect(editQuoteBody(7, 1001, lines)).toEqual({
      customer_id: 7,
      order: 1001,
      lines: [
        { sku_unit_id: 5, quantity: 6 },
        { sku_unit_id: 6, quantity: 10 },
      ],
    });
    expect(editQuoteBody(7, 1001, [{ ...lines[0]!, quantity: 0 }])).toBeNull();
    expect(editQuoteBody(null, 1001, lines)).toBeNull();
    expect(editQuoteBody(7, 1001, [])).toBeNull();
  });

  it("sends existing lines with their id, new ones without, and the trimmed note only when there is one", () => {
    const lines = [
      { order_item_id: 61, sku_unit_id: 5, quantity: 12, name: "أ", code: "A-1" },
      { order_item_id: null, sku_unit_id: 8, quantity: 5, name: "د", code: "D-1" },
    ];

    expect(editBody(lines, "  نفد ")).toEqual({
      lines: [
        { order_item_id: 61, sku_unit_id: 5, quantity: 12 },
        { sku_unit_id: 8, quantity: 5 },
      ],
      note: "نفد",
    });
    expect(editBody(lines, " ")).not.toHaveProperty("note");
  });

  it("blocks on a problem of an added or changed line only", () => {
    const quote = orderQuote({
      lines: [
        orderQuoteLine({ sku_unit_id: 5, problem: { reason: "insufficient_stock", requested: 12, available: 8 } }),
        orderQuoteLine({ sku_unit_id: 6, problem: { reason: "product_inactive" } }),
      ],
    });

    expect(editHasProblems(quote, [{ kind: "quantity", sku_unit_id: 5, name: "أ", from: 6, to: 12 }])).toBe(true);
    expect(editHasProblems(quote, [{ kind: "removed", sku_unit_id: 6, name: "ب", from: 10, to: null }])).toBe(false);
    expect(editHasProblems(quote, [])).toBe(false);
  });

  it("previews the items total: unchanged lines at their snapshot, the others at the quote", () => {
    const lines = [
      { order_item_id: 61, sku_unit_id: 5, quantity: 12, name: "أ", code: "A-1" },
      { order_item_id: 62, sku_unit_id: 6, quantity: 10, name: "ب", code: "B-1" },
    ];
    const quote = orderQuote({ lines: [orderQuoteLine({ sku_unit_id: 5, line_total: "1020.00" })] });

    expect(editedItemsTotal(order, lines, quote)).toBe("1870.00");
    expect(editedItemsTotal(order, lines, undefined)).toBeNull();
  });
});

describe("partial delivery (BR-O13)", () => {
  it("sends only the lines delivered short, nothing for a full delivery", () => {
    expect(deliveredBody(order, { 61: 4, 62: 10 })).toEqual([{ order_item_id: 61, quantity: 4 }]);
    expect(deliveredBody(order, { 61: 6 })).toBeUndefined();
  });

  it("previews what the delivered lines come to", () => {
    expect(deliveredItemsTotal(order, { 61: 4 })).toBe("1210.00");
    expect(deliveredItemsTotal(order, {})).toBe("1390.00");
  });

  it("keeps a delivered quantity between none and what was ordered", () => {
    expect([
      clampDelivered(7, 6),
      clampDelivered(-1, 6),
      clampDelivered(2.6, 6),
      clampDelivered(Number.NaN, 6),
    ]).toEqual([6, 0, 2, 0]);
  });
});
