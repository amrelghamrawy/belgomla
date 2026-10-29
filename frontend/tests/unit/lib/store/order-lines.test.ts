import { describe, expect, it } from "vitest";

import type { OrderItemChange } from "@/lib/api/types";
import { bundleNames, changedLineIds, groupOrderLines, liveItems, variantOf } from "@/lib/store/order-lines";

import { order as orderDetail, orderItem } from "../../fixtures";

describe("order lines after an edit (BR-O12, BR-O13)", () => {
  it("leaves the removed lines out of the live ones", () => {
    const order = orderDetail();
    const [line] = order.items;
    const removed = { ...line!, id: 62, removed_at: "2026-10-20T08:00:00Z" };

    expect(liveItems({ items: [line!, removed] }).map((item) => item.id)).toEqual([61]);
  });

  it("marks the lines added or given another quantity, not the removed or short ones", () => {
    const at = "2026-10-20T08:00:00Z";
    const change = { product_name: "أ", sku_code: "A-1", unit_price_before: null, unit_price_after: null, at };

    const changes: OrderItemChange[] = [
      { ...change, order_item_id: 61, change: "quantity", old_quantity: 6, new_quantity: 8 },
      { ...change, order_item_id: 62, change: "added", old_quantity: null, new_quantity: 5 },
      { ...change, order_item_id: 63, change: "removed", old_quantity: 4, new_quantity: null },
      { ...change, order_item_id: 64, change: "delivered_short", old_quantity: 4, new_quantity: 2 },
      { ...change, order_item_id: null, change: "added", old_quantity: null, new_quantity: 1 },
    ];

    expect(changedLineIds({ changes })).toEqual(new Set([61, 62]));
  });
});

describe("variantOf", () => {
  it("BR-P14 names the variant a line was ordered in, and nothing for a product without options", () => {
    expect(variantOf({ product_name: "قلم جاف", sku_name: "أزرق / M" })).toBe("أزرق / M");
    expect(variantOf({ product_name: "الأضواء علوم", sku_name: "الأضواء علوم " })).toBeNull();
    expect(variantOf({ product_name: "قلم", sku_name: "" })).toBeNull();
  });
});

describe("bundle lines (BR-P15, D93)", () => {
  const bundle = orderItem({ id: 70, product_name: "طقم أولى إعدادي", is_bundle: true, line_total: "135.00" });
  const first = orderItem({ id: 71, product_name: "الأضواء علوم", parent_item_id: 70 });
  const second = orderItem({ id: 72, product_name: "سلاح التلميذ", parent_item_id: 70 });
  const loose = orderItem({ id: 73, product_name: "قلم" });

  it("groups each bundle line with its component lines and keeps the others as they are", () => {
    const groups = groupOrderLines([bundle, first, second, loose]);

    expect(groups.map((group) => [group.item.id, group.components.map((item) => item.id)])).toEqual([
      [70, [71, 72]],
      [73, []],
    ]);
  });

  it("shows a component whose bundle line is not in the list on its own", () => {
    expect(groupOrderLines([first, loose]).map((group) => group.item.id)).toEqual([71, 73]);
  });

  it("leaves the component lines out of the live lines (they are not charged, edited or discounted)", () => {
    expect(liveItems({ items: [bundle, first, second, loose] }).map((item) => item.id)).toEqual([70, 73]);
  });

  it("names the bundle of each component line", () => {
    expect(bundleNames([bundle, first, second, loose])).toEqual({ 71: "طقم أولى إعدادي", 72: "طقم أولى إعدادي" });
  });
});
