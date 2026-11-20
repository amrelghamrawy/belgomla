"use client";

import Image from "next/image";

import type { StoreProductOption, StoreSku } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";
import { pickValue, selectionOf, valueState } from "@/lib/store/variants";

/**
 * V2 Task 14 (BR-P14): one selector per option of the product («اللون», «المقاس»), above the unit picker. A value with
 * a swatch image shows it; the others are buttons. A value whose combination with the other option's choice is not
 * sold, or has no stock, is disabled. Picking a value switches to its SKU (`onPick`).
 */
export function VariantPicker({
  options,
  skus,
  sku,
  onPick,
}: {
  options: readonly StoreProductOption[];
  skus: readonly StoreSku[];
  sku: StoreSku;
  onPick: (sku: StoreSku) => void;
}) {
  const selection = selectionOf(sku);

  if (options.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-4">
      {options.map((option, optionIndex) => {
        const chosen = option.values.find((value) => value.id === selection[optionIndex]);

        return (
          <div key={option.id} className="flex flex-col gap-2">
            <p className="text-sm">
              <span className="text-muted-foreground">{option.name}: </span>
              <span className="font-semibold">{chosen?.value}</span>
            </p>
            <div role="radiogroup" aria-label={option.name} className="flex flex-wrap gap-2">
              {option.values.map((value) => {
                const checked = value.id === selection[optionIndex];
                const state = valueState(skus, selection, optionIndex, value.id);
                const disabled = !checked && state !== "available";
                const label = state === "available" ? value.value : `${value.value} (${ar.product.valueUnavailable})`;

                return (
                  <button
                    key={value.id}
                    type="button"
                    role="radio"
                    aria-checked={checked}
                    aria-label={label}
                    disabled={disabled}
                    className={cn(
                      "relative inline-flex min-h-11 min-w-11 items-center justify-center overflow-hidden rounded-lg border-2 text-sm font-semibold transition-colors",
                      value.image_url === null && "px-3",
                      checked ? "border-primary text-primary" : "border-input hover:border-primary/50",
                      disabled && "cursor-not-allowed opacity-40",
                    )}
                    onClick={() => {
                      const next = pickValue(skus, selection, optionIndex, value.id);

                      if (next !== undefined && next.id !== sku.id) {
                        onPick(next);
                      }
                    }}
                  >
                    {value.image_url === null ? (
                      <span className={cn(disabled && "line-through")}>{value.value}</span>
                    ) : (
                      <Image src={value.image_url} alt="" width={44} height={44} className="size-11 object-cover" />
                    )}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
