import { describe, expect, it } from "vitest";

import { quantityBounds } from "@/lib/store/quantity";
import { skuInUnit, skuUnits, unitOf } from "@/lib/store/units";

import { hiddenPriceSku, storeSku, storeSkuUnit } from "../../fixtures";

const piece = storeSku({ unit: "قطعة", conversion_factor: 1, moq: 5, base_price: "10.00", tiers: [], available: 30 });
const carton = storeSkuUnit({ id: 602, conversion_factor: 12, moq: 1, base_price: "110.00", in_stock: true });
const sku = { ...piece, units: [...skuUnits(piece), carton] };

describe("SKU units (D91)", () => {
  it("finds the unit picked, else the default", () => {
    expect(unitOf(sku, 602)?.unit).toBe("كرتونة");
    expect(unitOf(sku, null)?.id).toBe(601);
    expect(unitOf(sku, 999)?.id).toBe(601);
    expect(unitOf({ ...sku, units: [] }, null)).toBeUndefined();
  });

  it("shows the SKU in the unit picked: its price, MOQ, factor and stock bounds", () => {
    const shown = skuInUnit(sku, carton);

    expect(shown).toMatchObject({
      id: 501,
      unit: "كرتونة",
      conversion_factor: 12,
      moq: 1,
      base_price: "110.00",
      tiers: [],
    });
    expect(quantityBounds(shown)).toEqual({ min: 1, max: 2 });
    expect(quantityBounds(skuInUnit(sku, unitOf(sku, null)))).toEqual({ min: 5, max: 30 });
  });

  it("keeps prices hidden when the unit has none, and is the SKU itself without a unit", () => {
    const hidden = hiddenPriceSku();

    expect(skuInUnit(hidden, unitOf(hidden, null)).base_price).toBeUndefined();
    expect(skuInUnit(sku, undefined)).toBe(sku);
  });
});
