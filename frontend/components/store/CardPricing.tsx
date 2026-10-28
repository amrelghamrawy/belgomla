"use client";

import { useState } from "react";

import type { StoreProduct } from "@/lib/api/types";
import { skuInUnit, skuUnits, unitOf } from "@/lib/store/units";

import { PriceBlock } from "./PriceBlock";
import { QuickAdd } from "./QuickAdd";
import { UnitPicker } from "./UnitPicker";

/**
 * The price panel and add-to-cart of a product card, with the unit picker between them when the SKU is sold in more
 * than one unit (V2 Task 13, D91): both follow the unit picked, the default first.
 */
export function CardPricing({ product, href }: { product: StoreProduct; href: string }) {
  const sku = product.sku;
  const [unitId, setUnitId] = useState<number | undefined>(() => (sku === null ? undefined : unitOf(sku, null)?.id));
  const shown = sku === null ? null : skuInUnit(sku, unitOf(sku, unitId));

  return (
    <>
      <div className="bg-brand-soft mt-1 rounded-xl px-2 py-2 sm:px-3 sm:py-2.5">
        <PriceBlock
          sku={shown}
          pricesHidden={product.prices_hidden === true}
          kind={product.category?.kind ?? null}
          loginNext={href}
          showMargin={false}
        />
      </div>
      <div className="mt-auto flex flex-col gap-2 pt-2">
        {sku !== null && product.prices_hidden !== true && (
          <UnitPicker units={skuUnits(sku)} value={unitId} onChange={setUnitId} size="sm" />
        )}
        <QuickAdd product={product} unitId={unitId} />
      </div>
    </>
  );
}
