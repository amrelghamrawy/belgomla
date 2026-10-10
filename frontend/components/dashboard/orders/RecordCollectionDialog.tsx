"use client";

import { type FormEvent, useState } from "react";
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
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { balanceOf, useRecordCollection } from "@/lib/dashboard/orders";
import { toPiasters } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.collections;

/** Records cash against the order; the amount starts at what is left (exceeds_balance comes from the server). */
export function RecordCollectionDialog({ order }: { order: DashboardOrderDetail }) {
  const record = useRecordCollection(order.number);
  const balance = balanceOf(order);
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState(balance);
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const piasters = toPiasters(amount);

    if (piasters === null || piasters === 0n) {
      setError(ar.dashboard.productForm.invalidMoney);
      return;
    }

    setError(null);
    record.mutate(
      { amount: amount.trim(), note: note.trim() === "" ? null : note.trim() },
      {
        onSuccess: () => {
          toast.success(copy.recorded);
          setOpen(false);
          setNote("");
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        setAmount(balance);
        setError(null);
      }}
    >
      <Button variant="outline" onClick={() => setOpen(true)}>
        {copy.record}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.recordTitle}</DialogTitle>
          <DialogDescription>{copy.remaining(formatMoney(balance))}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <Field data-invalid={error !== null}>
            <FieldLabel htmlFor="collection-amount">{copy.amount}</FieldLabel>
            <Input
              id="collection-amount"
              dir="ltr"
              inputMode="decimal"
              className="h-11 text-start"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="collection-note">{copy.note}</FieldLabel>
            <Textarea
              id="collection-note"
              rows={2}
              maxLength={300}
              value={note}
              onChange={(event) => setNote(event.target.value)}
            />
          </Field>
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={record.isPending}>
              {record.isPending ? ar.saving : copy.record}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
