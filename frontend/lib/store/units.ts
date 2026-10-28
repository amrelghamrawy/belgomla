import type { StoreSku, StoreSkuUnit } from "@/lib/api/types";

// V2 Task 13 (D91): a SKU may be sold in several units (piece, carton of 12, …), each with its own price, MOQ and
// tiers; stock stays in base units. The storefront picks a unit and shows the SKU "in" that unit.

/** The units a shopper can pick from, the default first (as the API lists them). */
export function skuUnits(sku: StoreSku): StoreSkuUnit[] {
  return sku.units ?? [];
}

/** The unit with this id, else the default (or the first) unit; undefined when the SKU lists none. */
export function unitOf(sku: StoreSku, unitId: number | null | undefined): StoreSkuUnit | undefined {
  const units = skuUnits(sku);

  return units.find((unit) => unit.id === unitId) ?? units.find((unit) => unit.is_default) ?? units[0];
}

/**
 * The SKU as seen in one of its units: that unit's name, factor, MOQ, prices, tiers and `in_stock`, so price boxes,
 * quantity bounds and tier tables read it like a single-unit SKU. Without a unit it is the SKU as it came.
 */
export function skuInUnit(sku: StoreSku, unit: StoreSkuUnit | undefined): StoreSku {
  if (unit === undefined) {
    return sku;
  }

  return {
    ...sku,
    unit: unit.unit,
    conversion_factor: unit.conversion_factor,
    moq: unit.moq,
    cover_price: unit.cover_price,
    base_price: unit.base_price,
    discount_percent: unit.discount_percent,
    tiers: unit.tiers,
    price_source: unit.price_source,
    promotion: unit.promotion,
    in_stock: unit.in_stock,
  };
}
