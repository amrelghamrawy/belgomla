"use client";

import { MinusIcon, PlusIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";
import { clampQuantity, type QuantityBounds } from "@/lib/store/quantity";

type QuantityStepperProps = {
  value: number;
  bounds: QuantityBounds;
  onChange: (quantity: number) => void;
  disabled?: boolean;
  id?: string;
};

/**
 * − / number / + in selling units (BR-MOQ01): never below the MOQ, never above what the stock covers. A typed
 * number is applied when the field loses focus (or on Enter), clamped into the bounds.
 */
export function QuantityStepper({ value, bounds, onChange, disabled = false, id = "quantity" }: QuantityStepperProps) {
  const [draft, setDraft] = useState(String(value));
  const [shown, setShown] = useState(value);

  // A new value from outside (a server answer, a clamp) replaces whatever is being typed.
  if (shown !== value) {
    setShown(value);
    setDraft(String(value));
  }

  const commit = () => {
    const next = clampQuantity(Number(draft), bounds);
    setDraft(String(next));

    if (next !== value) {
      onChange(next);
    }
  };

  return (
    <div className="flex items-center gap-1">
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-11"
        aria-label={ar.product.decrease}
        aria-controls={id}
        disabled={disabled || value <= bounds.min}
        onClick={() => onChange(clampQuantity(value - 1, bounds))}
      >
        <MinusIcon />
      </Button>
      <input
        id={id}
        type="number"
        inputMode="numeric"
        dir="ltr"
        aria-label={ar.product.quantity}
        min={bounds.min}
        max={bounds.max}
        value={draft}
        disabled={disabled}
        onChange={(event) => setDraft(event.target.value)}
        onBlur={commit}
        onKeyDown={(event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            commit();
          }
        }}
        className="border-input bg-background focus-visible:ring-ring/50 h-11 w-16 [appearance:textfield] rounded-lg border text-center tabular-nums outline-none focus-visible:ring-3 [&::-webkit-inner-spin-button]:appearance-none"
      />
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-11"
        aria-label={ar.product.increase}
        aria-controls={id}
        disabled={disabled || value >= bounds.max}
        onClick={() => onChange(clampQuantity(value + 1, bounds))}
      >
        <PlusIcon />
      </Button>
    </div>
  );
}
