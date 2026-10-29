import type { StoreProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";

// BR-P15, D93: a bundle on the storefront. It has no stock of its own: it is available while every component is, and
// a component with `in_stock: false` is the one holding it back.

export function isBundle(product: Pick<StoreProduct, "type">): boolean {
  return product.type === "bundle";
}

/** «وفّر 25.00 ج.م» when the bundle costs less than its components bought alone; null otherwise or with prices hidden. */
export function savingLabel(product: Pick<StoreProduct, "type" | "saving">): string | null {
  const saving = product.saving ?? null;

  if (!isBundle(product) || saving === null || !/^\d+\.\d{2}$/.test(saving) || Number(saving) <= 0) {
    return null;
  }

  return ar.catalog.saving(formatMoney(saving));
}

/** The names of the components that stop the bundle now, with their variant label when they have one. */
export function missingComponents(product: Pick<StoreProduct, "components">): string[] {
  return (product.components ?? [])
    .filter((component) => !component.in_stock)
    .map((component) => (component.label ? `${component.product_name} (${component.label})` : component.product_name));
}

/**
 * Why a bundle cannot be bought now, naming what is missing; null for a simple product or a bundle that can be made.
 * A bundle at 0 without a missing component named (the API did not say) still says it is unavailable.
 */
export function bundleUnavailableMessage(
  product: Pick<StoreProduct, "type" | "components" | "availability">,
): string | null {
  if (!isBundle(product) || (product.availability ?? 0) >= 1) {
    return null;
  }

  const missing = missingComponents(product);

  return missing.length === 0 ? ar.product.bundleUnavailableAny : ar.product.bundleUnavailable(missing.join("، "));
}
