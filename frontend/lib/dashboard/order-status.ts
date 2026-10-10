import type { OrderStatus } from "@/lib/api/types";

// A mirror of the backend's OrderStatus rules (app/Modules/Orders/Enums/OrderStatus.php, BR-O01/O02, D9,
// BR-C04), so the order page shows only the buttons the server will accept. The server stays the authority.

const STEPS: Record<OrderStatus, number> = {
  pending: 0,
  confirmed: 1,
  preparing: 2,
  out_for_delivery: 3,
  delivered: 4,
  cancelled: -1,
};

/** The forward statuses `transition` accepts, in path order. */
export const FORWARD: readonly ForwardStatus[] = ["confirmed", "preparing", "out_for_delivery", "delivered"];

export type ForwardStatus = "confirmed" | "preparing" | "out_for_delivery" | "delivered";

export function isTerminal(status: OrderStatus): boolean {
  return status === "delivered" || status === "cancelled";
}

/** BR-O01: forward only, skipping allowed; cancelled from any non-terminal status; terminal states stay. */
export function canTransition(from: OrderStatus, to: OrderStatus): boolean {
  if (isTerminal(from)) {
    return false;
  }

  return to === "cancelled" || STEPS[from] < STEPS[to];
}

/** The forward steps a merchant can take from here, e.g. confirmed → preparing, out_for_delivery, delivered. */
export function nextSteps(from: OrderStatus): ForwardStatus[] {
  return FORWARD.filter((to) => canTransition(from, to));
}

/** BR-O02: the merchant cancels from any status except delivered (and not twice). */
export function canCancel(from: OrderStatus): boolean {
  return canTransition(from, "cancelled");
}

/** D9: the delivery address can be edited until the order leaves for delivery. */
export function allowsAddressEdit(status: OrderStatus): boolean {
  return status === "pending" || status === "confirmed" || status === "preparing";
}

/** BR-C04: collections are recorded at any status except cancelled. */
export function canCollect(status: OrderStatus): boolean {
  return status !== "cancelled";
}
