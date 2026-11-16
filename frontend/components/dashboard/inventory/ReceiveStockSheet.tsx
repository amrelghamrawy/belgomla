"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import type { DashboardSku, InventoryRow } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useDashboardProduct } from "@/lib/dashboard/catalog";
import { useReceiveStock, useSetStockCost } from "@/lib/dashboard/inventory";
import { formatUnitCost } from "@/lib/format/money";

import { ErrorBlock, LoadingBlock } from "../common/States";

const copy = ar.dashboard.inventory;
const MONEY = /^\d{1,10}(\.\d{1,2})?$/;

export type CostMode = "receive" | "cost";

/**
 * BR-INV09, D96: receiving goods at the price paid, or setting the cost by hand. Both are typed in one of the SKU's
 * units (the base unit, or a carton…); the server turns them into base units and a cost per base unit, so nothing
 * is converted here (rules/03: the client never computes a price).
 */
export function ReceiveStockSheet({ row, mode, onClose }: { row: InventoryRow; mode: CostMode; onClose: () => void }) {
  const product = useDashboardProduct(row.product_id);
  const sku = product.data?.skus.find((candidate) => candidate.id === row.sku_id);

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="end" className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{mode === "receive" ? copy.receiveTitle(row.code) : copy.setCostTitle(row.code)}</SheetTitle>
          <SheetDescription>
            {row.name}
            {row.variant && ` — ${row.variant}`}
          </SheetDescription>
        </SheetHeader>
        <div className="px-4 pb-4">
          {product.isError && <ErrorBlock error={product.error} onRetry={() => void product.refetch()} />}
          {product.isPending && <LoadingBlock rows={3} />}
          {product.data !== undefined && (
            <CostForm row={row} sku={sku} mode={mode} onDone={onClose} />
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

export function CostForm({
  row,
  sku,
  mode,
  onDone,
}: {
  row: InventoryRow;
  sku: DashboardSku | undefined;
  mode: CostMode;
  onDone: () => void;
}) {
  const receive = useReceiveStock(row.sku_id);
  const setCost = useSetStockCost(row.sku_id);
  const pending = receive.isPending || setCost.isPending;
  const units = (sku?.units ?? []).filter((unit) => unit.conversion_factor > 1);
  const [unitId, setUnitId] = useState("");
  const [quantity, setQuantity] = useState("");
  const [unitCost, setUnitCost] = useState("");
  const [note, setNote] = useState("");
  const [problems, setProblems] = useState<{ quantity?: string; unitCost?: string }>({});
  const [failure, setFailure] = useState<unknown>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const next: typeof problems = {};

    if (mode === "receive" && !/^[1-9]\d{0,7}$/.test(quantity.trim())) {
      next.quantity = ar.dashboard.productForm.invalidInteger;
    }

    if (!MONEY.test(unitCost.trim())) {
      next.unitCost = copy.invalidCost;
    }

    setProblems(next);
    setFailure(null);

    if (Object.keys(next).length > 0) {
      return;
    }

    const skuUnitId = unitId === "" ? null : Number(unitId);
    const done = (message: string) => () => {
      toast.success(message);
      onDone();
    };

    if (mode === "receive") {
      receive.mutate(
        { quantity: Number(quantity), unit_cost: unitCost.trim(), sku_unit_id: skuUnitId, note: note.trim() || null },
        { onSuccess: done(copy.received), onError: setFailure },
      );

      return;
    }

    setCost.mutate({ unit_cost: unitCost.trim(), sku_unit_id: skuUnitId }, { onSuccess: done(copy.costSet), onError: setFailure });
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">{mode === "receive" ? copy.receiveHint : copy.setCostHint}</p>
      <p className="text-sm" data-testid="current-cost">
        {copy.avgCost}: {row.avg_cost ? copy.costPerBase(formatUnitCost(row.avg_cost)) : copy.noCost}
      </p>
      <Field>
        <FieldLabel htmlFor="cost-unit">{copy.receiveUnit}</FieldLabel>
        <NativeSelect id="cost-unit" value={unitId} onChange={(event) => setUnitId(event.target.value)}>
          <option value="">{copy.baseUnit(sku?.base_unit?.name ?? ar.dashboard.none)}</option>
          {units.map((unit) => (
            <option key={unit.id} value={unit.id}>
              {copy.unitOption(unit.unit?.name ?? ar.dashboard.none, unit.conversion_factor)}
            </option>
          ))}
        </NativeSelect>
      </Field>
      {mode === "receive" && (
        <Field data-invalid={problems.quantity !== undefined}>
          <FieldLabel htmlFor="receive-quantity">{copy.receiveQuantity}</FieldLabel>
          <Input
            id="receive-quantity"
            dir="ltr"
            inputMode="numeric"
            className="h-11 text-start"
            value={quantity}
            aria-invalid={problems.quantity !== undefined}
            onChange={(event) => setQuantity(event.target.value)}
          />
          {problems.quantity !== undefined && <FieldError>{problems.quantity}</FieldError>}
        </Field>
      )}
      <Field data-invalid={problems.unitCost !== undefined}>
        <FieldLabel htmlFor="unit-cost">{copy.unitCost}</FieldLabel>
        <Input
          id="unit-cost"
          dir="ltr"
          inputMode="decimal"
          className="h-11 text-start"
          value={unitCost}
          aria-invalid={problems.unitCost !== undefined}
          onChange={(event) => setUnitCost(event.target.value)}
        />
        <FieldDescription>{ar.currency}</FieldDescription>
        {problems.unitCost !== undefined && <FieldError>{problems.unitCost}</FieldError>}
      </Field>
      {mode === "receive" && (
        <Field>
          <FieldLabel htmlFor="receive-note">{copy.receiveNote}</FieldLabel>
          <Input
            id="receive-note"
            className="h-11"
            maxLength={200}
            placeholder={copy.receiveNotePlaceholder}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
        </Field>
      )}
      {failure !== null && <FieldError role="alert">{messageFor(failure)}</FieldError>}
      <Button type="submit" className="h-11" disabled={pending}>
        {pending ? ar.saving : ar.save}
      </Button>
    </form>
  );
}
