import type { OrderItem, OrderItemChange } from "@/lib/api/types";

// BR-O12, BR-O13: an order's lines after the merchant edited them or delivered short, for both order pages.
// BR-P15, D93: the items are a flat list where a bundle line (`is_bundle`) is followed by its component lines
// (`parent_item_id` = the bundle line). The bundle line is what the order charges; its components carry the stock.

/**
 * The top-level lines the merchant did not remove (BR-O12): what the order charges and what the merchant edits,
 * discounts and delivers. A bundle's component lines are left out (D93): they follow their bundle line.
 */
export function liveItems(order: { items: OrderItem[] }): OrderItem[] {
  return order.items.filter((item) => item.removed_at === null && item.parent_item_id === null);
}

/** A top-level line with the component lines of a bundle (empty for any other line). */
export type OrderLineGroup = { item: OrderItem; components: OrderItem[] };

/**
 * D93: the lines as the order pages show them: every top-level line in order, a bundle line holding its component
 * lines. A component whose bundle line is missing from the list is shown on its own rather than lost.
 */
export function groupOrderLines(items: OrderItem[]): OrderLineGroup[] {
  const ids = new Set(items.map((item) => item.id));
  const groups: OrderLineGroup[] = [];
  const byId = new Map<number, OrderLineGroup>();

  for (const item of items) {
    if (item.parent_item_id === null || !ids.has(item.parent_item_id)) {
      const group = { item, components: [] };
      groups.push(group);
      byId.set(item.id, group);
    }
  }

  for (const item of items) {
    if (item.parent_item_id !== null) {
      byId.get(item.parent_item_id)?.components.push(item);
    }
  }

  return groups;
}

/** D93: the bundle name of each component line, by line id (returns are recorded on the components). */
export function bundleNames(items: OrderItem[]): Record<number, string> {
  const names = new Map(items.filter((item) => item.is_bundle).map((item) => [item.id, item.product_name]));
  const result: Record<number, string> = {};

  for (const item of items) {
    const name = item.parent_item_id === null ? undefined : names.get(item.parent_item_id);

    if (name !== undefined) {
      result[item.id] = name;
    }
  }

  return result;
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
