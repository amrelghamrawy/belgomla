"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field";
import { Label } from "@/components/ui/label";
import type { ProductFilter, Promotion, PromotionPreview } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  cairoInputValue,
  useCreatePromotion,
  usePromotionPreview,
  useUpdatePromotion,
} from "@/lib/dashboard/promotions";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.promotions;

/** Mirrors the promotion requests: a name up to 100, a % above 0 and below 90 with 2 decimals, a Cairo window. */
export const promotionSchema = z
  .object({
    name: z.string().trim().min(1, ar.validation.required).max(100),
    discount_percent: z
      .string()
      .trim()
      .regex(/^\d{1,2}(\.\d{1,2})?$/, copy.invalidPercent)
      .refine((value) => Number(value) > 0 && Number(value) < 90, copy.invalidPercent),
    starts_at: z.string().min(1, ar.validation.required),
    ends_at: z.string().min(1, ar.validation.required),
    draft: z.boolean(),
  })
  .refine((values) => values.starts_at === "" || values.ends_at > values.starts_at, {
    message: copy.invalidWindow,
    path: ["ends_at"],
  });

type PromotionValues = z.infer<typeof promotionSchema>;

type PromotionDialogProps =
  | { filter: ProductFilter; promotion?: undefined; onClose: () => void }
  | { filter?: undefined; promotion: Promotion; onClose: () => void };

/**
 * BR-PR15 (V2 Task 12): a new promotion on the products the products page's filter selects (the bulk-pricing filter),
 * with a preview of how many SKUs it covers, or the edit of a draft or scheduled one. Times are Cairo wall time (D60).
 */
export function PromotionDialog({ filter, promotion, onClose }: PromotionDialogProps) {
  const create = useCreatePromotion();
  const update = useUpdatePromotion();
  const preview = usePromotionPreview();
  const [error, setError] = useState<string | null>(null);
  const [previewed, setPreviewed] = useState<PromotionPreview | null>(null);
  const form = useForm<PromotionValues>({
    resolver: zodResolver(promotionSchema),
    defaultValues: {
      name: promotion?.name ?? "",
      discount_percent: promotion?.discount_percent ?? "",
      starts_at: promotion === undefined ? "" : cairoInputValue(promotion.starts_at),
      ends_at: promotion === undefined ? "" : cairoInputValue(promotion.ends_at),
      draft: false,
    },
  });
  const errors = form.formState.errors;
  const draft = useWatch({ control: form.control, name: "draft" });
  const pending = create.isPending || update.isPending;

  const failed = (failure: Error) => {
    if (!applyServerErrors(failure, ["name", "discount_percent", "starts_at", "ends_at"], form.setError)) {
      setError(messageFor(failure));
    }
  };

  const runPreview = () => {
    if (filter === undefined) {
      return;
    }

    const percent = form.getValues("discount_percent").trim();

    setError(null);
    preview.mutate(
      { filter, ...(percent === "" ? {} : { discount_percent: percent }) },
      { onSuccess: setPreviewed, onError: (failure) => setError(messageFor(failure)) },
    );
  };

  const submit = form.handleSubmit((values) => {
    const fields = {
      name: values.name,
      discount_percent: values.discount_percent,
      starts_at: values.starts_at,
      ends_at: values.ends_at,
    };
    const done = {
      onSuccess: () => {
        toast.success(copy.saved);
        onClose();
      },
      onError: failed,
    };

    setError(null);

    if (promotion !== undefined) {
      update.mutate({ id: promotion.id, ...fields }, done);
    } else if (filter !== undefined) {
      create.mutate({ ...fields, filter, status: values.draft ? "draft" : "scheduled" }, done);
    }
  });

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{promotion === undefined ? copy.addTitle : copy.editTitle}</DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <TextField
            id="promotion-name"
            label={copy.name}
            placeholder={copy.namePlaceholder}
            registration={form.register("name")}
            error={errors.name}
          />
          <TextField
            id="promotion-percent"
            label={copy.percent}
            dir="ltr"
            inputMode="decimal"
            placeholder={copy.percentHint}
            registration={form.register("discount_percent", { onChange: () => setPreviewed(null) })}
            error={errors.discount_percent}
          />
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <TextField
              id="promotion-starts"
              type="datetime-local"
              label={copy.startsAt}
              dir="ltr"
              registration={form.register("starts_at")}
              error={errors.starts_at}
            />
            <TextField
              id="promotion-ends"
              type="datetime-local"
              label={copy.endsAt}
              dir="ltr"
              registration={form.register("ends_at")}
              error={errors.ends_at}
            />
          </div>
          {promotion === undefined && (
            <div className="flex items-center gap-2">
              <Checkbox
                id="promotion-draft"
                checked={draft}
                onCheckedChange={(checked) => form.setValue("draft", checked === true)}
              />
              <Label htmlFor="promotion-draft">{copy.draft}</Label>
            </div>
          )}
          {previewed !== null && (
            <Alert data-testid="promotion-preview">
              <AlertDescription className="flex flex-col gap-1">
                <span className="font-medium">{copy.previewResult(previewed.skus_count)}</span>
                {previewed.sample.length > 0 && <span>{copy.previewSample}</span>}
                <ul className="list-disc ps-5">
                  {previewed.sample.slice(0, 3).map((line) => (
                    <li key={line.sku_id}>
                      {line.product_name} (<bdi>{line.code}</bdi>): {formatMoney(line.base_price)}
                      {line.promotion_price !== null && <> ← {formatMoney(line.promotion_price)}</>}
                    </li>
                  ))}
                </ul>
              </AlertDescription>
            </Alert>
          )}
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            {filter !== undefined && (
              <Button type="button" variant="outline" disabled={preview.isPending} onClick={runPreview}>
                {preview.isPending ? ar.loading : copy.preview}
              </Button>
            )}
            <Button type="submit" disabled={pending}>
              {pending ? ar.saving : copy.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** The products page's «عرض ترويجي»: a promotion on the products its filter selects. */
export function NewPromotionButton({ filter }: { filter: ProductFilter }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        {copy.fromProducts}
      </Button>
      {open && <PromotionDialog filter={filter} onClose={() => setOpen(false)} />}
    </>
  );
}
