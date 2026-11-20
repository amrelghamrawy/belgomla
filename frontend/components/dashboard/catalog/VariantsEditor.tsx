"use client";

import { ChevronDownIcon, CopyIcon, RefreshCwIcon } from "lucide-react";
import { type UseFormReturn, useWatch } from "react-hook-form";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { SkuStatus, Unit } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { fromPiasters, toPiasters } from "@/lib/dashboard/price-math";
import {
  applyFirstToAll,
  type ProductFormValues,
  regenerateCodes,
  type VariantFormValues,
} from "@/lib/dashboard/product-form";
import { variantLabel } from "@/lib/dashboard/variants";
import { cn } from "@/lib/utils";

import { LabelText } from "../common/LabelText";
import { SkuUnitsEditor } from "./SkuUnitsEditor";

const copy = ar.dashboard.productForm.variants;
const STATUSES: SkuStatus[] = ["active", "inactive"];

type Props = {
  form: UseFormReturn<ProductFormValues>;
  units: Unit[];
  referenceLabel: string;
  /** Client tier problems per variant, then unit, then tier row. */
  tierErrors: Record<number, Record<number, Record<number, string>>>;
  /** Server tier problems per variant, then unit. */
  serverTierProblems: Record<number, Record<number, string[]>>;
  /** The variants whose units and prices are shown. */
  open: readonly number[];
  onOpenChange: (open: number[]) => void;
};

/** The default unit's price as the row's summary («كرتونة · 12.00 ج.م»). */
function priceSummary(variant: VariantFormValues, units: readonly Unit[]): string {
  const unit = variant.units[variant.default_unit] ?? variant.units[0];

  if (unit === undefined) {
    return copy.noPrice;
  }

  const name = units.find((option) => String(option.id) === unit.unit_id)?.name;
  const value = unit.base_value.trim();
  const piasters = toPiasters(value);
  const price =
    value === ""
      ? copy.noPrice
      : unit.base_mode === "percent"
        ? `${value}%`
        : piasters === null
          ? value
          : formatMoney(fromPiasters(piasters));

  return name === undefined ? price : `${name} · ${price}`;
}

/**
 * The variants of a product with options (V2 Task 14, BR-P14): one row per combination with its code, status, opening
 * stock (for a row this save creates) and its units and prices, folded until opened. «تطبيق على الكل» copies the first
 * row's units and prices to every row; the codes can be generated again from the product's code.
 */
export function VariantsEditor({
  form,
  units,
  referenceLabel,
  tierErrors,
  serverTierProblems,
  open,
  onOpenChange,
}: Props) {
  const variants = useWatch({ control: form.control, name: "variants" });
  const errors = form.formState.errors.variants;
  const rootError = errors?.message ?? errors?.root?.message;
  const validate = form.formState.isSubmitted;

  const replaceAll = (next: VariantFormValues[]) => {
    // Fold every row first: an open row's units editor is remounted on the new values.
    onOpenChange([]);
    form.setValue("variants", next, { shouldDirty: true, shouldValidate: validate });
  };

  const toggle = (index: number) =>
    onOpenChange(open.includes(index) ? open.filter((at) => at !== index) : [...open, index]);

  return (
    <section className="flex flex-col gap-4" aria-labelledby="product-variants-title">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 id="product-variants-title" className="font-bold">
            {copy.title}{" "}
            <span className="text-muted-foreground text-sm font-normal">({copy.count(variants.length)})</span>
          </h3>
          <p className="text-muted-foreground text-xs">{copy.hint}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() =>
              replaceAll(regenerateCodes(form.getValues("variants"), form.getValues("options"), form.getValues("code")))
            }
          >
            <RefreshCwIcon />
            {copy.regenerateCodes}
          </Button>
          {variants.length > 1 && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                replaceAll(applyFirstToAll(form.getValues("variants")));
                toast.success(copy.applied);
              }}
            >
              <CopyIcon />
              {copy.applyToAll}
            </Button>
          )}
        </div>
      </div>
      {variants.length > 1 && <p className="text-muted-foreground -mt-2 text-xs">{copy.applyToAllHint}</p>}
      {rootError !== undefined && <FieldError>{rootError}</FieldError>}
      {variants.length === 0 && <p className="text-muted-foreground text-sm">{copy.none}</p>}
      <ul className="flex flex-col gap-3">
        {variants.map((variant, index) => {
          const rowErrors = errors?.[index];
          const unitProblems =
            rowErrors?.units !== undefined ||
            Object.keys(tierErrors[index] ?? {}).length > 0 ||
            Object.keys(serverTierProblems[index] ?? {}).length > 0;
          const expanded = open.includes(index) || unitProblems;
          const label = variantLabel(variant.option_values);
          const id = (name: string) => `product-variants-${index}-${name}`;
          const set = (name: "code" | "opening_stock", value: string) =>
            form.setValue(`variants.${index}.${name}`, value, { shouldDirty: true, shouldValidate: validate });

          return (
            <li
              key={JSON.stringify(variant.option_values)}
              className={cn("rounded-xl border p-3", variant.status === "inactive" && "bg-muted/40")}
              data-testid={`variant-${index}`}
              aria-label={label}
            >
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <p className="font-bold sm:col-span-2 lg:col-span-1 lg:self-center">{label}</p>
                <Field data-invalid={rowErrors?.code !== undefined}>
                  <FieldLabel htmlFor={id("code")}>
                    <LabelText text={copy.code} need="required" />
                  </FieldLabel>
                  <Input
                    id={id("code")}
                    dir="ltr"
                    className="text-start"
                    value={variant.code}
                    aria-invalid={rowErrors?.code !== undefined}
                    onChange={(event) => set("code", event.target.value)}
                  />
                  <FieldError errors={[rowErrors?.code]} />
                </Field>
                <Field>
                  <FieldLabel htmlFor={id("status")}>{copy.status}</FieldLabel>
                  <NativeSelect
                    id={id("status")}
                    value={variant.status}
                    onChange={(event) =>
                      form.setValue(`variants.${index}.status`, event.target.value as SkuStatus, {
                        shouldDirty: true,
                        shouldValidate: validate,
                      })
                    }
                  >
                    {STATUSES.map((status) => (
                      <option key={status} value={status}>
                        {copy.statuses[status]}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
                {variant.id === "" && (
                  <Field data-invalid={rowErrors?.opening_stock !== undefined}>
                    <FieldLabel htmlFor={id("opening_stock")}>
                      <LabelText text={copy.openingStock} need="optional" />
                    </FieldLabel>
                    <Input
                      id={id("opening_stock")}
                      dir="ltr"
                      inputMode="numeric"
                      className="text-start"
                      value={variant.opening_stock}
                      aria-invalid={rowErrors?.opening_stock !== undefined}
                      onChange={(event) => set("opening_stock", event.target.value)}
                    />
                    <FieldError errors={[rowErrors?.opening_stock]} />
                  </Field>
                )}
              </div>
              <FieldError errors={[rowErrors?.option_values]} />
              <div className="mt-2 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                <span className="text-muted-foreground text-sm" data-testid={`variant-${index}-price`}>
                  {priceSummary(variant, units)}
                </span>
                {unitProblems && <span className="text-destructive text-xs">{copy.hasErrors}</span>}
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  aria-expanded={expanded}
                  aria-controls={id("units")}
                  disabled={unitProblems}
                  onClick={() => toggle(index)}
                >
                  {expanded ? copy.hidePrices : copy.prices}
                  <ChevronDownIcon className={cn("transition-transform", expanded && "rotate-180")} />
                </Button>
              </div>
              {expanded && (
                <div id={id("units")} className="pt-3">
                  <SkuUnitsEditor
                    form={form}
                    variant={index}
                    units={units}
                    referenceLabel={referenceLabel}
                    tierErrors={tierErrors[index] ?? {}}
                    serverTierProblems={serverTierProblems[index] ?? {}}
                  />
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
