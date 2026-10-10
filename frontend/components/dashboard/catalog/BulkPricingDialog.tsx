"use client";

import { PlusIcon, TrashIcon } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import type { BulkPricingRequest, BulkPricingResult, ProductFilter } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { toBasisPoints } from "@/lib/dashboard/price-math";
import { useBulkPricing, useBulkPricingPreview } from "@/lib/dashboard/pricing";

const copy = ar.dashboard.products;
const formCopy = ar.dashboard.productForm;

type TierRow = { min_quantity: string; discount_percent: string };

/** The request body, or the problem that keeps it from being sent. */
export function bulkPricingBody(
  filter: ProductFilter,
  percent: string,
  tiers: readonly TierRow[],
  moq: string,
): BulkPricingRequest | string {
  if (toBasisPoints(percent) === null) {
    return formCopy.invalidPercent;
  }

  const seen = new Set<string>();

  for (const tier of tiers) {
    if (!/^\d+$/.test(tier.min_quantity) || Number(tier.min_quantity) < 1) {
      return formCopy.tierErrors.invalidQuantity;
    }

    if (seen.has(tier.min_quantity)) {
      return formCopy.tierErrors.duplicate(Number(tier.min_quantity));
    }

    seen.add(tier.min_quantity);

    if (toBasisPoints(tier.discount_percent) === null) {
      return formCopy.invalidPercent;
    }
  }

  if (moq !== "" && (!/^\d+$/.test(moq) || Number(moq) < 1)) {
    return formCopy.invalidInteger;
  }

  return {
    filter,
    base_price_percent: percent.trim(),
    tiers: tiers.map((tier) => ({
      min_quantity: Number(tier.min_quantity),
      discount_percent: tier.discount_percent.trim(),
    })),
    ...(moq === "" ? {} : { moq: Number(moq) }),
  };
}

/**
 * BR-PR11: price every matching product at X% off its cover price, with optional percentage tiers and MOQ.
 * A preview runs first and shows how many products change and which are skipped for having no cover price;
 * Apply stays disabled until the shown preview matches the inputs.
 */
export function BulkPricingDialog({ filter }: { filter: ProductFilter }) {
  const preview = useBulkPricingPreview();
  const apply = useBulkPricing();
  const [open, setOpen] = useState(false);
  const [percent, setPercent] = useState("");
  const [moq, setMoq] = useState("");
  const [tiers, setTiers] = useState<TierRow[]>([]);
  const [problem, setProblem] = useState<string | null>(null);
  const [result, setResult] = useState<{ body: string; data: BulkPricingResult } | null>(null);
  const body = bulkPricingBody(filter, percent, tiers, moq);
  const previewed = typeof body !== "string" && result !== null && result.body === JSON.stringify(body);

  const reset = () => {
    setProblem(null);
    setResult(null);
  };

  const runPreview = () => {
    if (typeof body === "string") {
      setProblem(body);
      return;
    }

    setProblem(null);
    preview.mutate(body, {
      onSuccess: (data) => setResult({ body: JSON.stringify(body), data }),
      onError: (failure) => setProblem(messageFor(failure)),
    });
  };

  const runApply = () => {
    if (typeof body === "string" || !previewed) {
      return;
    }

    apply.mutate(body, {
      onSuccess: (data) => {
        toast.success(copy.bulkPricingDone(data.affected));
        setOpen(false);
        reset();
      },
      onError: (failure) => setProblem(messageFor(failure)),
    });
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        {copy.bulkPricing}
      </Button>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{copy.bulkPricingTitle}</DialogTitle>
          <DialogDescription>{formCopy.tiersHint}</DialogDescription>
        </DialogHeader>
        <div className="grid grid-cols-2 gap-3">
          <Field>
            <FieldLabel htmlFor="bulk-percent">{copy.basePercent}</FieldLabel>
            <Input
              id="bulk-percent"
              dir="ltr"
              inputMode="decimal"
              className="text-start"
              value={percent}
              onChange={(event) => {
                setPercent(event.target.value);
                reset();
              }}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="bulk-moq">{copy.bulkMoq}</FieldLabel>
            <Input
              id="bulk-moq"
              dir="ltr"
              inputMode="numeric"
              className="text-start"
              value={moq}
              onChange={(event) => {
                setMoq(event.target.value);
                reset();
              }}
            />
          </Field>
        </div>
        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium">{formCopy.tiers}</legend>
          {tiers.map((tier, index) => (
            <div key={index} className="flex items-center gap-2">
              <Input
                aria-label={`${formCopy.tierMin} ${index + 1}`}
                dir="ltr"
                inputMode="numeric"
                className="w-24 text-start"
                value={tier.min_quantity}
                onChange={(event) => {
                  setTiers(tiers.map((row, at) => (at === index ? { ...row, min_quantity: event.target.value } : row)));
                  reset();
                }}
              />
              <Input
                aria-label={`${copy.basePercent} ${index + 1}`}
                dir="ltr"
                inputMode="decimal"
                className="w-24 text-start"
                value={tier.discount_percent}
                onChange={(event) => {
                  setTiers(
                    tiers.map((row, at) => (at === index ? { ...row, discount_percent: event.target.value } : row)),
                  );
                  reset();
                }}
              />
              <span className="text-muted-foreground text-xs">%</span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={formCopy.removeTier}
                onClick={() => {
                  setTiers(tiers.filter((_, at) => at !== index));
                  reset();
                }}
              >
                <TrashIcon />
              </Button>
            </div>
          ))}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="self-start"
            onClick={() => {
              setTiers([...tiers, { min_quantity: "", discount_percent: "" }]);
              reset();
            }}
          >
            <PlusIcon />
            {formCopy.addTier}
          </Button>
        </fieldset>
        {problem !== null && <FieldError role="alert">{problem}</FieldError>}
        {result !== null && (
          <Alert data-testid="bulk-preview">
            <AlertDescription className="flex flex-col gap-1">
              <span className="font-medium">{copy.previewResult(result.data.affected)}</span>
              {result.data.skipped_no_cover_price.length > 0 && (
                <>
                  <span>{copy.skippedNoCover(result.data.skipped_no_cover_price.length)}</span>
                  <ul className="list-disc ps-5">
                    {result.data.skipped_no_cover_price.map((skipped) => (
                      <li key={skipped.sku_id}>
                        {skipped.name} (<bdi>{skipped.code}</bdi>)
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" disabled={preview.isPending} onClick={runPreview}>
            {preview.isPending ? ar.loading : copy.preview}
          </Button>
          <Button disabled={!previewed || apply.isPending} onClick={runApply}>
            {apply.isPending ? ar.saving : copy.apply}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
