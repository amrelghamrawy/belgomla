"use client";

import { PlusIcon, TrashIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ar } from "@/lib/copy/ar";
import { type TierDraft, tierPrice } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

import { LabelText } from "../common/LabelText";
import { NativeSelect } from "../common/NativeSelect";

const copy = ar.dashboard.productForm;

type Props = {
  tiers: TierDraft[];
  onChange: (tiers: TierDraft[]) => void;
  cover: string | null;
  /** Problems by row index (tierProblems), shown under each row. */
  problems: Record<number, string>;
};

/** Quantity tiers: "from N units" at a price or a percentage off the cover price (BR-PR04, BR-PR10). */
export function TiersEditor({ tiers, onChange, cover, problems }: Props) {
  const update = (index: number, patch: Partial<TierDraft>) =>
    onChange(tiers.map((tier, at) => (at === index ? { ...tier, ...patch } : tier)));

  return (
    <fieldset className="flex flex-col gap-3">
      <legend className="font-medium">
        <LabelText text={copy.tiers} need="optional" />
      </legend>
      <p className="text-muted-foreground text-xs">{copy.tiersHint}</p>
      {tiers.map((tier, index) => {
        const price = tierPrice(tier, cover);
        const problem = problems[index];

        return (
          <div key={index} className="flex flex-col gap-1" data-testid={`tier-${index}`}>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                aria-label={`${copy.tierMin} ${index + 1}`}
                inputMode="numeric"
                dir="ltr"
                className="w-24 text-start"
                value={tier.min_quantity}
                aria-invalid={problem !== undefined}
                onChange={(event) => update(index, { min_quantity: event.target.value })}
              />
              <NativeSelect
                aria-label={copy.priceMode}
                value={tier.mode}
                onChange={(event) => update(index, { mode: event.target.value as TierDraft["mode"] })}
              >
                <option value="price">{copy.byPrice}</option>
                <option value="percent">{copy.byPercent}</option>
              </NativeSelect>
              <Input
                aria-label={`${copy.tierValue} ${index + 1}`}
                inputMode="decimal"
                dir="ltr"
                className="w-28 text-start"
                value={tier.value}
                aria-invalid={problem !== undefined}
                onChange={(event) => update(index, { value: event.target.value })}
              />
              {tier.mode === "percent" && price !== null && (
                <span className="text-muted-foreground text-xs">{copy.equalsPrice(formatMoney(price))}</span>
              )}
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={copy.removeTier}
                onClick={() => onChange(tiers.filter((_, at) => at !== index))}
              >
                <TrashIcon />
              </Button>
            </div>
            {problem !== undefined && <FieldError>{problem}</FieldError>}
          </div>
        );
      })}
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() =>
          onChange([...tiers, { min_quantity: "", mode: cover === null ? "price" : "percent", value: "" }])
        }
      >
        <PlusIcon />
        {copy.addTier}
      </Button>
    </fieldset>
  );
}
