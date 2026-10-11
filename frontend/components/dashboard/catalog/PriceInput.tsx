"use client";

import type { ReactNode } from "react";

import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ar } from "@/lib/copy/ar";
import { percentFromPrice, priceFromPercent } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.productForm;

export type PriceMode = "price" | "percent";

type Props = {
  id: string;
  label: ReactNode;
  mode: PriceMode;
  onModeChange: (mode: PriceMode) => void;
  value: string;
  onChange: (value: string) => void;
  /** The reference price as typed, or null when there is none (a percentage then cannot be used). */
  cover: string | null;
  /** What the reference price is called for this product's section (ar.product.referencePrice, D68). */
  referenceLabel: string;
  error?: string;
};

/**
 * A price entered either as an amount or as a discount off the reference price (BR-PR10). Shows what the other
 * form means: "= 90.00 ج.م" for a percentage, "خصم 25%" for an amount. The server computes the stored price.
 */
export function PriceInput({ id, label, mode, onModeChange, value, onChange, cover, referenceLabel, error }: Props) {
  const computed = mode === "percent" && cover !== null ? priceFromPercent(cover, value) : null;
  const implied = mode === "price" && cover !== null ? percentFromPrice(cover, value) : null;

  return (
    <Field data-invalid={error !== undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <div className="flex items-center gap-2">
        <div role="group" aria-label={copy.priceMode} className="flex shrink-0 rounded-lg border p-0.5">
          {(["price", "percent"] as const).map((option) => (
            <Button
              key={option}
              type="button"
              size="sm"
              variant={mode === option ? "default" : "ghost"}
              aria-pressed={mode === option}
              disabled={option === "percent" && cover === null}
              onClick={() => onModeChange(option)}
            >
              {option === "price" ? copy.byPrice : "%"}
            </Button>
          ))}
        </div>
        <Input
          id={id}
          dir="ltr"
          inputMode="decimal"
          className="text-start"
          value={value}
          aria-invalid={error !== undefined}
          onChange={(event) => onChange(event.target.value)}
        />
      </div>
      <p className="text-muted-foreground min-h-5 text-xs" data-testid={`${id}-hint`}>
        {mode === "percent" && cover === null && copy.percentNeedsCover(referenceLabel)}
        {computed !== null && copy.equalsPrice(formatMoney(computed))}
        {implied !== null && copy.impliedDiscount(implied, referenceLabel)}
      </p>
      {error !== undefined && <FieldError>{error}</FieldError>}
    </Field>
  );
}
