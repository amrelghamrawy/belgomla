"use client";

import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { ar } from "@/lib/copy/ar";
import type { DiscountDraft } from "@/lib/dashboard/price-math";

const copy = ar.dashboard.orders.discount;

type DiscountInputProps = {
  id: string;
  /** What the discount is for, read by screen readers on both controls (e.g. the product name). */
  label: string;
  draft: DiscountDraft;
  invalid?: boolean;
  onChange: (draft: DiscountDraft) => void;
};

/** BR-PR08, D85: one discount as a value and an EGP / % switch. An empty value is no discount. */
export function DiscountInput({ id, label, draft, invalid = false, onChange }: DiscountInputProps) {
  return (
    <span className="flex items-center gap-1">
      <Input
        id={id}
        aria-label={copy.line(label)}
        aria-invalid={invalid}
        dir="ltr"
        inputMode="decimal"
        className="w-24 text-center"
        value={draft.value}
        onChange={(event) => onChange({ ...draft, value: event.target.value })}
      />
      <NativeSelect
        aria-label={copy.type(label)}
        className="w-20"
        value={draft.type}
        onChange={(event) => onChange({ ...draft, type: event.target.value === "amount" ? "amount" : "percent" })}
      >
        <NativeSelectOption value="percent">{copy.percent}</NativeSelectOption>
        <NativeSelectOption value="amount">{copy.amount}</NativeSelectOption>
      </NativeSelect>
    </span>
  );
}
