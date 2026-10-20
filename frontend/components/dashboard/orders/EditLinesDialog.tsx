"use client";

import { type FormEvent, useMemo, useState } from "react";
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
  DialogTrigger,
} from "@/components/ui/dialog";
import { Field, FieldLabel } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  editBody,
  editedItemsTotal,
  editHasProblems,
  type EditLine,
  editLinesOf,
  editQuoteBody,
  type LineChange,
  lineChanges,
  liveItems,
} from "@/lib/dashboard/edit-order";
import { addLine, removeLine, setQuantity, useOrderQuote, useSettled } from "@/lib/dashboard/new-order";
import { useEditOrderLines } from "@/lib/dashboard/orders";
import { formatMoney } from "@/lib/format/money";

import { OrderLinesTable } from "./OrderLinesTable";
import { ProductPicker } from "./ProductPicker";

const copy = ar.dashboard.orders.edit;

/** BR-O12, D87: the «تعديل الأصناف» button of a pending or confirmed order and its dialog. */
export function EditLinesDialog({ order }: { order: DashboardOrderDetail }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline">{copy.button}</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{copy.title}</DialogTitle>
          <DialogDescription>{copy.hint}</DialogDescription>
        </DialogHeader>
        {open && <EditLinesForm order={order} onDone={() => setOpen(false)} />}
      </DialogContent>
    </Dialog>
  );
}

/**
 * The new-order line editor (OrderLinesTable) on the order's live lines, quoted with the order so what it holds counts
 * as stock, and the diff before saving. The server stays the authority: it re-checks everything under lock
 * (422 checkout_failed, 409 order_locked / below_collected) and answers whether the order went under the minimum.
 */
function EditLinesForm({ order, onDone }: { order: DashboardOrderDetail; onDone: () => void }) {
  const edit = useEditOrderLines(order.number);
  const [lines, setLines] = useState<EditLine[]>(() => editLinesOf(order));
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const body = useMemo(() => editQuoteBody(order.customer?.id ?? null, order.number, lines), [order, lines]);
  const quote = useOrderQuote(useSettled(body));
  const shown = body === null ? undefined : quote.data;
  const changes = lineChanges(order, lines);
  const total = editedItemsTotal(order, lines, shown);
  const marks = Object.fromEntries(
    changes
      .filter((change) => change.kind !== "removed")
      .map((change) => [change.sku_id, change.kind === "added" ? copy.addedMark : ar.dashboard.orders.changedBadge]),
  );
  const ready =
    changes.length > 0 && body !== null && shown !== undefined && !quote.isFetching && !editHasProblems(shown, changes);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    edit.mutate(editBody(lines, note), {
      onSuccess: ({ belowMov }) => {
        toast.success(copy.saved);

        if (belowMov !== null) {
          toast.warning(copy.belowMov(formatMoney(belowMov.required), formatMoney(belowMov.current)));
        }

        onDone();
      },
      onError: (failure) => setError(messageFor(failure)),
    });
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <div className="flex justify-end">
        <ProductPicker
          onPick={(product) => {
            // A line of the order removed in this form and picked again is the same line, not a new one.
            const existing = liveItems(order).find((item) => item.sku_id === product.sku.id);

            setLines((current) =>
              addLine(current, {
                order_item_id: existing?.id ?? null,
                sku_id: product.sku.id,
                quantity: existing?.quantity ?? product.sku.moq,
                name: product.name,
                code: product.sku.code,
              }),
            );
          }}
        />
      </div>
      {lines.length === 0 ? (
        <p className="text-muted-foreground text-sm">{copy.emptyLines}</p>
      ) : (
        <OrderLinesTable
          lines={lines}
          quote={shown}
          discounts={null}
          priced={null}
          marks={marks}
          onQuantity={(skuId, quantity) => setLines((current) => setQuantity(current, skuId, quantity))}
          onRemove={(skuId) => setLines((current) => removeLine(current, skuId))}
        />
      )}
      {quote.isError && body !== null && (
        <Alert variant="destructive">
          <AlertDescription>{messageFor(quote.error)}</AlertDescription>
        </Alert>
      )}
      <section className="flex flex-col gap-1 rounded-md border p-3 text-sm" data-testid="edit-changes">
        <h3 className="font-medium">{copy.changes}</h3>
        {changes.length === 0 ? (
          <p className="text-muted-foreground">{copy.noChanges}</p>
        ) : (
          <ul className="flex flex-col gap-0.5">
            {changes.map((change) => (
              <li key={`${change.kind}-${change.sku_id}`}>{describe(change)}</li>
            ))}
          </ul>
        )}
        <p className="flex justify-between font-bold">
          <span>{copy.newTotal}</span>
          <span data-testid="edit-total">{total === null ? "—" : formatMoney(total)}</span>
        </p>
      </section>
      <Field>
        <FieldLabel htmlFor="edit-note">{copy.note}</FieldLabel>
        <Textarea
          id="edit-note"
          rows={2}
          maxLength={250}
          value={note}
          onChange={(event) => setNote(event.target.value)}
        />
      </Field>
      {error !== null && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <DialogFooter>
        <Button type="submit" disabled={!ready || edit.isPending}>
          {edit.isPending ? copy.saving : copy.save}
        </Button>
      </DialogFooter>
    </form>
  );
}

function describe(change: LineChange): string {
  if (change.kind === "added") {
    return copy.added(change.name, change.to ?? 0);
  }

  if (change.kind === "removed") {
    return copy.removed(change.name, change.from ?? 0);
  }

  return copy.quantity(change.name, change.from ?? 0, change.to ?? 0);
}
