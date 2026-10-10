import type { Cart } from "@/lib/api/types";

// Quantity rules the storefront enforces before the server does (BR-MOQ01, D37). The server stays the
// authority; these keep the stepper from offering a quantity it will refuse.

type Stock = { moq: number; conversion_factor: number; available?: number };

export type QuantityBounds = { min: number; max: number };

/**
 * `min` is the MOQ (selling units). `max` is how many selling units the stock covers: `available` is in base
 * units, so it is divided by the conversion factor. No `available` (coming soon, D21) means nothing can be ordered.
 */
export function quantityBounds(stock: Stock): QuantityBounds {
  const max = stock.available === undefined ? 0 : Math.floor(stock.available / Math.max(1, stock.conversion_factor));

  return { min: Math.max(1, stock.moq), max };
}

export function canOrder(bounds: QuantityBounds): boolean {
  return bounds.max >= bounds.min;
}

/** Keeps a typed or stepped quantity inside the bounds (whole numbers only). */
export function clampQuantity(quantity: number, bounds: QuantityBounds): number {
  if (!Number.isFinite(quantity)) {
    return bounds.min;
  }

  return Math.min(Math.max(Math.trunc(quantity), bounds.min), Math.max(bounds.min, bounds.max));
}

/** "1020.50" → 102050. Cart totals and the minimum are never negative. */
function toPiasters(money: string): number {
  const [whole = "0", fraction = ""] = money.split(".");

  return Number(whole) * 100 + Number(fraction.padEnd(2, "0").slice(0, 2));
}

function fromPiasters(piasters: number): string {
  return `${Math.floor(piasters / 100)}.${String(piasters % 100).padStart(2, "0")}`;
}

export type MovProgress = { remaining: string; percent: number; satisfied: boolean };

/**
 * How far the cart is from the minimum order value (BR-MOV02), for the progress bar. Null when the tenant has no
 * minimum. `satisfied` comes from the server; the remaining amount is display arithmetic on whole piasters.
 */
export function movProgress(cart: Pick<Cart, "items_total" | "mov">): MovProgress | null {
  if (!cart.mov.enabled || cart.mov.amount === null) {
    return null;
  }

  const required = toPiasters(cart.mov.amount);
  const total = toPiasters(cart.items_total);
  const remaining = Math.max(0, required - total);

  return {
    remaining: fromPiasters(remaining),
    percent: required === 0 ? 100 : Math.min(100, Math.floor((total / required) * 100)),
    satisfied: cart.mov.satisfied,
  };
}
