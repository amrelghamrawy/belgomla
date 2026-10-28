"use client";

import type { StoreSkuUnit } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

/**
 * V2 Task 13 (D91): a segmented control over the units a SKU is sold in («قطعة | كرتونة 12»). Shown only when there is
 * more than one; picking one switches the price, MOQ, tiers and stock shown, and the unit sent to the cart.
 */
export function UnitPicker({
  units,
  value,
  onChange,
  size = "lg",
}: {
  units: StoreSkuUnit[];
  value: number | undefined;
  onChange: (unitId: number) => void;
  size?: "sm" | "lg";
}) {
  if (units.length < 2) {
    return null;
  }

  return (
    <div role="radiogroup" aria-label={ar.product.unitPicker} className="bg-muted flex w-full gap-1 rounded-lg p-1">
      {units.map((unit) => {
        const checked = unit.id === value;
        const name = unit.unit ?? "";

        return (
          <button
            key={unit.id}
            type="button"
            role="radio"
            aria-checked={checked}
            className={cn(
              "flex-1 rounded-md px-2 font-semibold transition-colors",
              size === "lg" ? "h-11 text-sm" : "h-9 text-xs",
              checked ? "bg-background text-primary shadow-sm" : "text-muted-foreground hover:text-foreground",
            )}
            onClick={() => onChange(unit.id)}
          >
            {unit.conversion_factor > 1 ? ar.product.unitWithFactor(name, unit.conversion_factor) : name}
          </button>
        );
      })}
    </div>
  );
}
