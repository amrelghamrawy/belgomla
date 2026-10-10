"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import type { InventoryRow } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { belowReservedDetails, useAdjustStock, useInventoryRow } from "@/lib/dashboard/inventory";

import { ErrorBlock, LoadingBlock } from "../common/States";
import { BelowReservedError } from "./BelowReservedError";

const copy = ar.dashboard.inventory;

/** The adjust drawer: reads the SKU's stock fresh, shows the minimum allowed, and the BR-INV08 refusal. */
export function AdjustStockSheet({ row, onClose }: { row: InventoryRow; onClose: () => void }) {
  const fresh = useInventoryRow(row.sku_id);

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="end" className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{copy.adjustTitle(row.code)}</SheetTitle>
          <SheetDescription>{row.name}</SheetDescription>
        </SheetHeader>
        <div className="px-4 pb-4">
          {fresh.isError && <ErrorBlock error={fresh.error} onRetry={() => void fresh.refetch()} />}
          {fresh.isPending && <LoadingBlock rows={3} />}
          {fresh.data && <AdjustForm row={fresh.data} onDone={onClose} />}
        </div>
      </SheetContent>
    </Sheet>
  );
}

export function AdjustForm({ row, onDone }: { row: InventoryRow; onDone: () => void }) {
  const adjust = useAdjustStock(row.sku_id);
  const [onHand, setOnHand] = useState(String(row.on_hand));
  const [reason, setReason] = useState("");
  const [problems, setProblems] = useState<{ onHand?: string; reason?: string }>({});
  const [failure, setFailure] = useState<unknown>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const next: typeof problems = {};

    if (!/^\d{1,8}$/.test(onHand.trim())) {
      next.onHand = ar.dashboard.productForm.invalidInteger;
    }

    if (reason.trim() === "") {
      next.reason = ar.dashboard.reasonRequired;
    }

    setProblems(next);
    setFailure(null);

    if (Object.keys(next).length > 0) {
      return;
    }

    adjust.mutate(
      { new_on_hand: Number(onHand), reason: reason.trim() },
      {
        onSuccess: () => {
          toast.success(copy.adjusted);
          onDone();
        },
        onError: setFailure,
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {/* BR-INV08: on hand, reserved and available side by side, and the minimum allowed. */}
      <dl className="grid grid-cols-3 gap-2 text-center">
        {(
          [
            [copy.onHand, row.on_hand],
            [copy.reserved, row.reserved],
            [copy.available, row.available],
          ] as const
        ).map(([label, value]) => (
          <div key={label} className="rounded-md border p-2">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-lg font-bold">{value}</dd>
          </div>
        ))}
      </dl>
      <p className="text-sm font-medium" data-testid="min-allowed">
        {copy.minAllowedLine(row.min_allowed)}
      </p>
      <Field data-invalid={problems.onHand !== undefined}>
        <FieldLabel htmlFor="adjust-on-hand">{copy.newOnHand}</FieldLabel>
        <Input
          id="adjust-on-hand"
          dir="ltr"
          inputMode="numeric"
          className="h-11 text-start"
          min={row.min_allowed}
          value={onHand}
          aria-invalid={problems.onHand !== undefined}
          onChange={(event) => setOnHand(event.target.value)}
        />
        {problems.onHand !== undefined && <FieldError>{problems.onHand}</FieldError>}
      </Field>
      <Field data-invalid={problems.reason !== undefined}>
        <FieldLabel htmlFor="adjust-reason">{copy.reason}</FieldLabel>
        <Input
          id="adjust-reason"
          className="h-11"
          maxLength={200}
          placeholder={copy.reasonPlaceholder}
          value={reason}
          aria-invalid={problems.reason !== undefined}
          onChange={(event) => setReason(event.target.value)}
        />
        {problems.reason !== undefined && <FieldError>{problems.reason}</FieldError>}
      </Field>
      <BelowReservedError error={failure} />
      {failure !== null && belowReservedDetails(failure) === null && (
        <FieldError role="alert">{messageFor(failure)}</FieldError>
      )}
      <Button type="submit" className="h-11" disabled={adjust.isPending}>
        {adjust.isPending ? ar.saving : ar.save}
      </Button>
    </form>
  );
}
