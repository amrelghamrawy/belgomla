import { describe, expect, it } from "vitest";

import type { OrderItemChange } from "@/lib/api/types";
import { changedLineIds, liveItems } from "@/lib/store/order-lines";

import { order as orderDetail } from "../../fixtures";

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
