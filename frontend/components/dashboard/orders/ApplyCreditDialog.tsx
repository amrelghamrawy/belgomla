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
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import type { CustomerCredit, DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useApplyCredit, useCustomerCredits } from "@/lib/dashboard/customers";
import { balanceOf } from "@/lib/dashboard/orders";
import { fromPiasters, toPiasters } from "@/lib/dashboard/price-math";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.orders;

/** The smaller of what the credit holds and what the order still owes: the amount the dialog starts with. */
export function creditToApply(credit: Pick<CustomerCredit, "remaining">, balance: string): string {
  const remaining = toPiasters(credit.remaining) ?? 0n;
  const owed = toPiasters(balance) ?? 0n;

  return fromPiasters(remaining < owed ? remaining : owed);
}

/**
 * BR-C07 "خصم من رصيد العميل": shown while the order owes money and its customer has unused credit; pays part of the
 * order from one credit (exceeds_credit / exceeds_balance come from the server).
 */
export function ApplyCreditDialog({ order }: { order: DashboardOrderDetail }) {
  const credits = useCustomerCredits(order.customer?.id);
  const usable = (credits.data ?? []).filter((credit) => !credit.is_voided && credit.remaining !== "0.00");

  if (usable.length === 0) {
    return null;
  }

  return <ApplyCreditForm order={order} credits={usable} />;
}

function ApplyCreditForm({ order, credits }: { order: DashboardOrderDetail; credits: CustomerCredit[] }) {
  const apply = useApplyCredit(order.number);
  const balance = balanceOf(order);
  const first = credits[0] as CustomerCredit;
  const [open, setOpen] = useState(false);
  const [creditId, setCreditId] = useState(first.id);
  const [amount, setAmount] = useState(creditToApply(first, balance));
  const [error, setError] = useState<string | null>(null);

  const pick = (id: number) => {
    const credit = credits.find((candidate) => candidate.id === id) ?? first;
    setCreditId(credit.id);
    setAmount(creditToApply(credit, balance));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (toPiasters(amount) === null || toPiasters(amount) === 0n) {
      setError(ar.dashboard.productForm.invalidMoney);
      return;
    }

    setError(null);
    apply.mutate(
      { credit_id: creditId, amount: amount.trim() },
      {
        onSuccess: () => {
          toast.success(copy.creditApplied);
          setOpen(false);
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
        pick(creditId);
        setError(null);
      }}
    >
      <Button variant="outline" onClick={() => setOpen(true)}>
        {copy.applyCredit}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.applyCreditTitle}</DialogTitle>
          <DialogDescription>{copy.applyCreditHint(formatMoney(balance))}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <Field>
            <FieldLabel htmlFor="credit-choice">{copy.creditChoice}</FieldLabel>
            <NativeSelect
              id="credit-choice"
              value={String(creditId)}
              onChange={(event) => pick(Number(event.target.value))}
            >
              {credits.map((credit) => (
                <NativeSelectOption key={credit.id} value={String(credit.id)}>
                  {copy.creditOption(formatMoney(credit.remaining), formatDate(credit.created_at))}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          </Field>
          <Field data-invalid={error !== null}>
            <FieldLabel htmlFor="credit-amount">{copy.creditAmount}</FieldLabel>
            <Input
              id="credit-amount"
              dir="ltr"
              inputMode="decimal"
              className="h-11 text-start"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          </Field>
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={apply.isPending}>
              {apply.isPending ? ar.saving : copy.applyCredit}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
