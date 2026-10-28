"use client";

import { PlusIcon, TrashIcon } from "lucide-react";
import { Controller, type UseFormReturn, useFieldArray, useWatch } from "react-hook-form";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { Unit } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { priceFromPercent } from "@/lib/dashboard/price-math";
import { MAX_UNITS, type ProductFormValues, unitDraft } from "@/lib/dashboard/product-form";

import { LabelText } from "../common/LabelText";
import { PriceInput } from "./PriceInput";
import { TiersEditor } from "./TiersEditor";

const copy = ar.dashboard.productForm;

type Props = {
  form: UseFormReturn<ProductFormValues>;
  units: Unit[];
  referenceLabel: string;
  /** Client tier problems per unit index, then row index. */
  tierErrors: Record<number, Record<number, string>>;
  /** Server tier problems per unit index. */
  serverTierProblems: Record<number, string[]>;
  /** The variant whose units these are (V2 Task 14); the SKU's own units when left out. */
  variant?: number;
};

/**
 * «وحدات البيع» (V2 Task 13, D91): one card per unit the SKU is sold in, each with its unit, factor, reference price,
 * price or %, MOQ and tiers; a radio picks the default unit. At least one unit, at most ten.
 */
export function SkuUnitsEditor({ form, units, referenceLabel, tierErrors, serverTierProblems, variant }: Props) {
  // The same editor serves the SKU of a product without options and each variant of one with options.
  const path = variant === undefined ? ("units" as const) : (`variants.${variant}.units` as const);
  const defaultPath = variant === undefined ? ("default_unit" as const) : (`variants.${variant}.default_unit` as const);
  const scope = variant === undefined ? "product" : `product-v${variant}`;
  const { fields, append, remove } = useFieldArray({ control: form.control, name: path, keyName: "key" });
  const values = useWatch({ control: form.control, name: path });
  const defaultUnit = useWatch({ control: form.control, name: defaultPath });
  const errors = variant === undefined ? form.formState.errors.units : form.formState.errors.variants?.[variant]?.units;

  const removeAt = (index: number) => {
    remove(index);

    if (defaultUnit === index) {
      form.setValue(defaultPath, 0);
    } else if (defaultUnit > index) {
      form.setValue(defaultPath, defaultUnit - 1);
    }
  };

  return (
    <section className="flex flex-col gap-4" aria-labelledby={`${scope}-units-title`}>
      <div>
        <h3 id={`${scope}-units-title`} className="font-bold">
          {copy.units.title}
        </h3>
        <p className="text-muted-foreground text-xs">{copy.units.hint}</p>
      </div>
      {errors?.message !== undefined && <FieldError>{errors.message}</FieldError>}
      {fields.map((field, index) => {
        const unit = values[index] ?? unitDraft();
        const cover = unit.cover_price.trim() === "" ? null : unit.cover_price.trim();
        const basePrice =
          unit.base_mode === "percent"
            ? cover === null
              ? null
              : priceFromPercent(cover, unit.base_value)
            : unit.base_value.trim() || null;
        const unitErrors = errors?.[index];
        const id = (name: string) => `${scope}-units-${index}-${name}`;

        return (
          <fieldset
            key={field.key}
            className="grid grid-cols-1 gap-4 rounded-xl border p-4 md:grid-cols-2"
            data-testid={variant === undefined ? `sku-unit-${index}` : `variant-${variant}-unit-${index}`}
            data-base-price={basePrice ?? ""}
          >
            <legend className="px-1 font-medium">{copy.units.unit(index + 1)}</legend>
            <div className="flex items-center justify-between gap-2 md:col-span-2">
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name={`${scope}-default_unit`}
                  className="size-4"
                  checked={defaultUnit === index}
                  onChange={() => form.setValue(defaultPath, index, { shouldDirty: true })}
                />
                {copy.units.isDefault}
                <span className="text-muted-foreground text-xs">{copy.units.defaultHint}</span>
              </label>
              {fields.length > 1 && (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={copy.units.remove}
                  onClick={() => removeAt(index)}
                >
                  <TrashIcon />
                </Button>
              )}
            </div>
            <Field data-invalid={unitErrors?.unit_id !== undefined}>
              <FieldLabel htmlFor={id("unit_id")}>
                <LabelText text={copy.sellingUnit} need="required" />
              </FieldLabel>
              <NativeSelect
                id={id("unit_id")}
                aria-invalid={unitErrors?.unit_id !== undefined}
                {...form.register(`${path}.${index}.unit_id`)}
              >
                <option value="">{copy.chooseUnit}</option>
                {units.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.name}
                  </option>
                ))}
              </NativeSelect>
              <FieldError errors={[unitErrors?.unit_id]} />
            </Field>
            <Field data-invalid={unitErrors?.conversion_factor !== undefined}>
              <FieldLabel htmlFor={id("conversion_factor")}>
                <LabelText text={copy.conversion} need="required" />
              </FieldLabel>
              <Input
                id={id("conversion_factor")}
                dir="ltr"
                inputMode="numeric"
                className="text-start"
                aria-invalid={unitErrors?.conversion_factor !== undefined}
                {...form.register(`${path}.${index}.conversion_factor`)}
              />
              <FieldError errors={[unitErrors?.conversion_factor]} />
            </Field>
            <Field data-invalid={unitErrors?.cover_price !== undefined}>
              <FieldLabel htmlFor={id("cover_price")}>
                <LabelText text={referenceLabel} need="optional" />
              </FieldLabel>
              <Input
                id={id("cover_price")}
                dir="ltr"
                inputMode="decimal"
                className="text-start"
                aria-invalid={unitErrors?.cover_price !== undefined}
                {...form.register(`${path}.${index}.cover_price`)}
              />
              <FieldError errors={[unitErrors?.cover_price]} />
            </Field>
            <Controller
              control={form.control}
              name={`${path}.${index}.base_value`}
              render={({ field: input }) => (
                <PriceInput
                  id={id("base_value")}
                  label={<LabelText text={copy.basePrice} need="required" />}
                  mode={unit.base_mode}
                  onModeChange={(mode) => form.setValue(`${path}.${index}.base_mode`, mode)}
                  value={input.value}
                  onChange={input.onChange}
                  cover={cover}
                  referenceLabel={referenceLabel}
                  error={unitErrors?.base_value?.message}
                />
              )}
            />
            <Field data-invalid={unitErrors?.moq !== undefined}>
              <FieldLabel htmlFor={id("moq")}>
                <LabelText text={copy.moq} need="required" />
              </FieldLabel>
              <Input
                id={id("moq")}
                dir="ltr"
                inputMode="numeric"
                className="text-start"
                aria-invalid={unitErrors?.moq !== undefined}
                {...form.register(`${path}.${index}.moq`)}
              />
              <FieldError errors={[unitErrors?.moq]} />
            </Field>
            <div className="md:col-span-2">
              {(serverTierProblems[index] ?? []).length > 0 && (
                <Alert variant="destructive" className="mb-3">
                  <AlertDescription>
                    <ul>
                      {(serverTierProblems[index] ?? []).map((problem) => (
                        <li key={problem}>{problem}</li>
                      ))}
                    </ul>
                  </AlertDescription>
                </Alert>
              )}
              <Controller
                control={form.control}
                name={`${path}.${index}.tiers`}
                render={({ field: input }) => (
                  <TiersEditor
                    tiers={input.value}
                    onChange={input.onChange}
                    cover={cover}
                    referenceLabel={referenceLabel}
                    problems={tierErrors[index] ?? {}}
                  />
                )}
              />
            </div>
          </fieldset>
        );
      })}
      {fields.length < MAX_UNITS && (
        <Button type="button" variant="outline" className="self-start" onClick={() => append(unitDraft())}>
          <PlusIcon />
          {copy.units.add}
        </Button>
      )}
    </section>
  );
}
