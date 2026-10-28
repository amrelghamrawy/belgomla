import type { StoreSku } from "@/lib/api/types";

// V2 Task 14 (BR-P14, D92): a product with options lists its active SKUs, each naming one value id per option
// (`option_value_ids`, in option order). The product page picks a SKU through the options; these pure helpers decide
// which SKU a selection means and which values can be picked next to the current choice of the other option.

type WithSkus = { sku: StoreSku | null; skus: readonly StoreSku[] };

/** A selection: the chosen value id per option, in option order. */
export type Selection = readonly (number | undefined)[];

export type ValueState = "available" | "out_of_stock" | "unavailable";

/** The value ids a SKU stands for, in option order (empty for a product without options). */
export function selectionOf(sku: StoreSku | null | undefined): number[] {
  return sku?.option_value_ids ?? [];
}

/** The SKU with exactly this combination of values, if the product sells it. */
export function skuForSelection(skus: readonly StoreSku[], selection: Selection): StoreSku | undefined {
  return skus.find((sku) => {
    const ids = selectionOf(sku);

    return ids.length === selection.length && ids.every((id, index) => id === selection[index]);
  });
}

/** A SKU a shopper can pick: in stock, or Coming Soon (booked as a pre-order, D88). */
export function canPick(sku: StoreSku): boolean {
  return sku.in_stock || sku.coming_soon === true;
}

/**
 * Whether a value of one option can be picked while the other options keep their current choice: `unavailable` when
 * that combination is not sold (no active SKU), `out_of_stock` when its SKU has no stock.
 */
export function valueState(
  skus: readonly StoreSku[],
  selection: Selection,
  optionIndex: number,
  valueId: number,
): ValueState {
  const wanted = selection.map((id, index) => (index === optionIndex ? valueId : id));
  const sku = skuForSelection(skus, wanted);

  if (sku === undefined) {
    return "unavailable";
  }

  return canPick(sku) ? "available" : "out_of_stock";
}

/**
 * The SKU to switch to when a value is picked: the combination with the other options kept when it can be picked,
 * else the first SKU with that value that can be picked, else that combination, else the first SKU with that value.
 */
export function pickValue(
  skus: readonly StoreSku[],
  selection: Selection,
  optionIndex: number,
  valueId: number,
): StoreSku | undefined {
  const exact = skuForSelection(
    skus,
    selection.map((id, index) => (index === optionIndex ? valueId : id)),
  );

  if (exact !== undefined && canPick(exact)) {
    return exact;
  }

  const withValue = skus.filter((sku) => selectionOf(sku)[optionIndex] === valueId);

  return withValue.find(canPick) ?? exact ?? withValue[0];
}

/**
 * The SKU the product page shows: the one `?sku=` names when the product sells it, else the product's own SKU (the
 * first active one in stock), else the first listed; null when none is active.
 */
export function selectedSku(product: WithSkus, skuParam: string | null): StoreSku | null {
  const id = skuParam === null || !/^\d+$/.test(skuParam) ? null : Number(skuParam);
  const named = id === null ? undefined : product.skus.find((sku) => sku.id === id);

  return named ?? product.sku ?? product.skus[0] ?? null;
}
