import { describe, expect, it } from "vitest";

import type { StoreSku } from "@/lib/api/types";
import { canPick, pickValue, selectedSku, selectionOf, skuForSelection, valueState } from "@/lib/store/variants";

import { comingSoonSku, penProduct, storeProduct, storeSku } from "../../fixtures";

/** Colour (1 أزرق, 2 أسود) × size (5 S, 6 M): blue/M has no stock, black/S is not sold. */
function sku(id: number, ids: number[], inStock = true): StoreSku {
  return storeSku({ id, option_value_ids: ids, in_stock: inStock });
}

const skus = [sku(1, [1, 5]), sku(2, [1, 6], false), sku(3, [2, 6])];

describe("variant selection", () => {
  it("finds the SKU of a combination", () => {
    expect(skuForSelection(skus, [1, 6])?.id).toBe(2);
    expect(skuForSelection(skus, [2, 5])).toBeUndefined();
    expect(skuForSelection(skus, [1])).toBeUndefined();
    expect(selectionOf(null)).toEqual([]);
  });

  it("BR-P14 disables a value whose combination with the other choice is not sold or has no stock", () => {
    // Blue/S is chosen: M with blue has no stock, black with S is not sold.
    expect(valueState(skus, [1, 5], 1, 6)).toBe("out_of_stock");
    expect(valueState(skus, [1, 5], 0, 2)).toBe("unavailable");
    expect(valueState(skus, [1, 5], 1, 5)).toBe("available");
    // With M chosen, black can be picked.
    expect(valueState(skus, [1, 6], 0, 2)).toBe("available");
  });

  it("lets a Coming Soon variant be picked (it is booked, D88)", () => {
    expect(canPick(comingSoonSku())).toBe(true);
    expect(canPick(storeSku({ in_stock: false }))).toBe(false);
  });

  it("switches to the combination with the other choice kept, else the first SKU with the value", () => {
    expect(pickValue(skus, [1, 6], 0, 2)?.id).toBe(3);
    expect(pickValue(skus, [1, 5], 0, 2)?.id).toBe(3);
    expect(pickValue([sku(4, [2, 5], false), sku(5, [2, 6])], [1, 5], 0, 2)?.id).toBe(5);
    expect(pickValue([sku(4, [2, 5], false)], [1, 5], 0, 2)?.id).toBe(4);
    expect(pickValue(skus, [1, 5], 0, 9)).toBeUndefined();
  });

  it("starts from the SKU the URL names when the product sells it, else the product's SKU", () => {
    const pen = penProduct();

    expect(selectedSku(pen, "82")?.id).toBe(82);
    expect(selectedSku(pen, "999")?.id).toBe(81);
    expect(selectedSku(pen, "abc")?.id).toBe(81);
    expect(selectedSku(pen, null)?.id).toBe(81);
    expect(selectedSku({ ...pen, sku: null }, null)?.id).toBe(81);
    expect(selectedSku(storeProduct({ sku: null }), null)).toBeNull();
  });
});
