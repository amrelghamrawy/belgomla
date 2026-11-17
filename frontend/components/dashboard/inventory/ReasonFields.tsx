"use client";

import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { MerchantAdjustmentReason } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

const copy = ar.dashboard.inventory;

export const ADJUSTMENT_REASONS = Object.keys(copy.reasons) as MerchantAdjustmentReason[];

export type ReasonValue = { reason: MerchantAdjustmentReason | ""; note: string };

/** D97: the problem with a reason and its note, or null: a reason is chosen, and "other" says what happened. */
export function reasonProblem(value: ReasonValue): { reason?: string; note?: string } | null {
  if (value.reason === "") {
    return { reason: copy.chooseReason };
  }

  if (value.reason === "other" && value.note.trim() === "") {
    return { note: copy.noteRequired };
  }

  return null;
}

/** The reason from the fixed list and the note (required only for "other"), for one adjustment or a whole batch. */
export function ReasonFields({
  id,
  label,
  value,
  problems,
  onChange,
}: {
  id: string;
  label: string;
  value: ReasonValue;
  problems: { reason?: string; note?: string };
  onChange: (value: ReasonValue) => void;
}) {
  return (
    <>
      <Field data-invalid={problems.reason !== undefined}>
        <FieldLabel htmlFor={`${id}-reason`}>{label}</FieldLabel>
        <NativeSelect
          id={`${id}-reason`}
          value={value.reason}
          aria-invalid={problems.reason !== undefined}
          onChange={(event) => onChange({ ...value, reason: event.target.value as MerchantAdjustmentReason | "" })}
        >
          <option value="">{copy.chooseReason}</option>
          {ADJUSTMENT_REASONS.map((reason) => (
            <option key={reason} value={reason}>
              {copy.reasons[reason]}
            </option>
          ))}
        </NativeSelect>
        {problems.reason !== undefined && <FieldError>{problems.reason}</FieldError>}
      </Field>
      <Field data-invalid={problems.note !== undefined}>
        <FieldLabel htmlFor={`${id}-note`}>{value.reason === "other" ? copy.note : copy.noteOptional}</FieldLabel>
        <Input
          id={`${id}-note`}
          className="h-11"
          maxLength={150}
          placeholder={copy.notePlaceholder}
          value={value.note}
          aria-invalid={problems.note !== undefined}
          onChange={(event) => onChange({ ...value, note: event.target.value })}
        />
        {problems.note !== undefined && <FieldError>{problems.note}</FieldError>}
      </Field>
    </>
  );
}
