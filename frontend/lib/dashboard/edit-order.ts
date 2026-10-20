import type { OrderItem, OrderLinesRequest, OrderQuote } from "@/lib/api/types";
import { liveItems } from "@/lib/store/order-lines";

import { type DraftLine, quotedLine } from "./new-order";

export { liveItems };
import { fromPiasters, toPiasters } from "./price-math";

// BR-O12, D87: the merchant edits the lines of a pending or confirmed order (PUT /api/dashboard/orders/{number}/lines,
// the whole list of lines), quoting it like the new-order form with `order` so what the order holds counts as stock.
// BR-O13, D86: on delivery, what each line actually delivered. Pure helpers; the server prices and decides.

/** A line of the edit form: an existing line keeps its id, a new one has none. Quantity in selling units. */
export type EditLine = DraftLine & { order_item_id: number | null };

export type LineChange = {
  kind: "added" | "removed" | "quantity";
  sku_id: number;
  name: string;
  from: number | null;
  to: number | null;
};

/** The edit form starts from the order's live lines as they are. */
export function editLinesOf(order: { items: OrderItem[] }): EditLine[] {
  return liveItems(order).map((item) => ({
    order_item_id: item.id,
    sku_id: item.sku_id,
    quantity: item.quantity,
    name: item.product_name,
    code: item.sku_code,
  }));
}

/** What saving would change, in the order of the lines: removed lines, then changed and added ones. */
export function lineChanges(order: { items: OrderItem[] }, lines: EditLine[]): LineChange[] {
  const live = liveItems(order);
  const kept = new Set(lines.map((line) => line.order_item_id));
  const removed: LineChange[] = live
    .filter((item) => !kept.has(item.id))
    .map((item) => ({ kind: "removed", sku_id: item.sku_id, name: item.product_name, from: item.quantity, to: null }));
  const rest: LineChange[] = [];

  for (const line of lines) {
    const item = live.find((current) => current.id === line.order_item_id);

    if (item === undefined) {
      rest.push({ kind: "added", sku_id: line.sku_id, name: line.name, from: null, to: line.quantity });
    } else if (item.quantity !== line.quantity) {
      rest.push({ kind: "quantity", sku_id: line.sku_id, name: line.name, from: item.quantity, to: line.quantity });
    }
  }

  return [...removed, ...rest];
}

/** The quote of the edit (the order's own stock counts), or null while a line has no valid quantity. */
export function editQuoteBody(customerId: number | null, orderNumber: number, lines: EditLine[]) {
  if (customerId === null || lines.length === 0 || lines.some((line) => line.quantity < 1)) {
    return null;
  }

  return {
    customer_id: customerId,
    order: orderNumber,
    lines: lines.map(({ sku_id, quantity }) => ({ sku_id, quantity })),
  };
}

export function editBody(lines: EditLine[], note: string): OrderLinesRequest {
  const trimmed = note.trim();

  return {
    lines: lines.map(({ order_item_id, sku_id, quantity }) =>
      order_item_id === null ? { sku_id, quantity } : { order_item_id, sku_id, quantity },
    ),
    ...(trimmed === "" ? {} : { note: trimmed }),
  };
}

/** Whether an added or changed line has a problem in the quote (unchanged lines are not checked again, BR-O05). */
export function editHasProblems(quote: OrderQuote | undefined, changes: LineChange[]): boolean {
  return changes.some((change) => change.kind !== "removed" && quotedLine(quote, change.sku_id)?.problem != null);
}

/**
 * The items total the edit would give, for the preview: unchanged lines at their snapshot, added and changed lines at
 * the quoted price. null until every such line is quoted. A line's manual discount (D85) is applied by the server.
 */
export function editedItemsTotal(
  order: { items: OrderItem[] },
  lines: EditLine[],
  quote: OrderQuote | undefined,
): string | null {
  const live = liveItems(order);
  let total = 0n;

  for (const line of lines) {
    const item = live.find((current) => current.id === line.order_item_id);
    const amount =
      item !== undefined && item.quantity === line.quantity
        ? item.line_total
        : quotedLine(quote, line.sku_id)?.line_total;

    if (amount === undefined) {
      return null;
    }

    total += toPiasters(amount) ?? 0n;
  }

  return fromPiasters(total);
}

/** BR-O13: the delivered quantities to send: only the lines delivered short, or undefined for a full delivery. */
export function deliveredBody(
  order: { items: OrderItem[] },
  delivered: Record<number, number>,
): { order_item_id: number; quantity: number }[] | undefined {
  const short = liveItems(order)
    .filter((item) => (delivered[item.id] ?? item.quantity) < item.quantity)
    .map((item) => ({ order_item_id: item.id, quantity: delivered[item.id] ?? item.quantity }));

  return short.length === 0 ? undefined : short;
}

/** BR-O13: what the delivered lines come to before an order discount (display only; the server recomputes). */
export function deliveredItemsTotal(order: { items: OrderItem[] }, delivered: Record<number, number>): string {
  let total = 0n;

  for (const item of liveItems(order)) {
    total += (toPiasters(item.unit_price) ?? 0n) * BigInt(delivered[item.id] ?? item.quantity);
  }

  return fromPiasters(total);
}

/** A delivered quantity typed in the stepper: a whole number between 0 and what was ordered. */
export function clampDelivered(value: number, ordered: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }

  return Math.min(Math.max(Math.trunc(value), 0), ordered);
}
