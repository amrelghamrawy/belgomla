"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import type { StoreProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { skuInUnit, skuUnits, unitOf } from "@/lib/store/units";

import { PriceBlock } from "./PriceBlock";
import { QuickAdd } from "./QuickAdd";
import { UnitPicker } from "./UnitPicker";

/**
 * The price panel and add-to-cart of a product card, with the unit picker between them when the SKU is sold in more
 * than one unit (V2 Task 13, D91): both follow the unit picked, the default first. A product with variants (V2 Task 14,
 * BR-P14) shows its price range when the variants' prices differ, and links to its page to pick one instead of adding.
 */
export function CardPricing({ product, href }: { product: StoreProduct; href: string }) {
  const sku = product.sku;
  const [unitId, setUnitId] = useState<number | undefined>(() => (sku === null ? undefined : unitOf(sku, null)?.id));
  const shown = sku === null ? null : skuInUnit(sku, unitOf(sku, unitId));
  const range = product.prices_hidden === true ? null : (product.price_range ?? null);
  const variants = product.skus.length > 1;

  return (
    <>
      <div className="bg-brand-soft mt-1 rounded-xl px-2 py-2 sm:px-3 sm:py-2.5">
        {range !== null && range.min !== range.max ? (
          <p className="text-sm font-bold tabular-nums" data-testid="price-range">
            {ar.catalog.priceRange(formatMoney(range.min), formatMoney(range.max))}
          </p>
        ) : (
          <PriceBlock
            sku={shown}
            pricesHidden={product.prices_hidden === true}
            kind={product.category?.kind ?? null}
            loginNext={href}
            showMargin={false}
          />
        )}
      </div>
      <div className="mt-auto flex flex-col gap-2 pt-2">
        {variants ? (
          product.prices_hidden !== true && (
            <Button asChild variant="outline" className="h-11 w-full">
              <Link href={href} aria-label={ar.catalog.chooseOptionsLabel(product.name)}>
                {ar.catalog.chooseOptions}
              </Link>
            </Button>
          )
        ) : (
          <>
            {sku !== null && product.prices_hidden !== true && (
              <UnitPicker units={skuUnits(sku)} value={unitId} onChange={setUnitId} size="sm" />
            )}
            <QuickAdd product={product} unitId={unitId} />
          </>
        )}
      </div>
    </>
  );
}
