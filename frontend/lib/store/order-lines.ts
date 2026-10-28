import type { OrderItem, OrderItemChange } from "@/lib/api/types";

// BR-O12, BR-O13: an order's lines after the merchant edited them or delivered short, for both order pages.

/** The lines the merchant did not remove (BR-O12): what the order charges, holds and delivers. */
export function liveItems(order: { items: OrderItem[] }): OrderItem[] {
  return order.items.filter((item) => item.removed_at === null);
}

/** The lines added or given another quantity after the order was placed, by line id, to mark them. */
export function changedLineIds(order: { changes: Pick<OrderItemChange, "change" | "order_item_id">[] }): Set<number> {
  return new Set(
    order.changes
      .filter((change) => change.change === "added" || change.change === "quantity")
      .map((change) => change.order_item_id)
      .filter((id): id is number => id !== null),
  );
}

/**
 * The variant a line was ordered in (V2 Task 14, BR-P14): the SKU name snapshot («أزرق / M») when it says more than the
 * product name; null for a product without options, whose SKU name is the product's.
 */
export function variantOf(item: { product_name: string; sku_name: string }): string | null {
  const name = item.sku_name.trim();

  return name === "" || name === item.product_name.trim() ? null : name;
}
