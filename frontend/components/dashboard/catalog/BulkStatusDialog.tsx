"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { NativeSelect } from "@/components/ui/native-select";
import type { ProductFilter, ProductStatus } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useBulkProductStatus } from "@/lib/dashboard/catalog";

const copy = ar.dashboard.products;

/** Sets the status of every product matching the filter (BR-P12, D29: disallowed transitions are skipped). */
export function BulkStatusDialog({ filter, count }: { filter: ProductFilter; count: number }) {
  const bulk = useBulkProductStatus();
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState<ProductStatus>("inactive");
  const [error, setError] = useState<string | null>(null);

  const apply = () => {
    setError(null);
    bulk.mutate(
      { filter, status },
      {
        onSuccess: (result) => {
          toast.success(copy.bulkStatusDone(result.affected, result.skipped));
          setOpen(false);
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        {copy.bulkStatus}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.bulkStatusTitle}</DialogTitle>
          <DialogDescription>{copy.bulkScope(count)}</DialogDescription>
        </DialogHeader>
        <FieldLabel htmlFor="bulk-status">{copy.newStatus}</FieldLabel>
        <NativeSelect
          id="bulk-status"
          value={status}
          onChange={(event) => setStatus(event.target.value as ProductStatus)}
        >
          {(["active", "coming_soon", "inactive"] as const).map((value) => (
            <option key={value} value={value}>
              {ar.dashboard.productStatuses[value]}
            </option>
          ))}
        </NativeSelect>
        {error !== null && <FieldError role="alert">{error}</FieldError>}
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            {ar.cancel}
          </Button>
          <Button disabled={bulk.isPending} onClick={apply}>
            {bulk.isPending ? ar.saving : copy.apply}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
