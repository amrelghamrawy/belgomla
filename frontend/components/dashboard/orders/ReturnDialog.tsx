"use client";

import { MinusIcon, PlusIcon } from "lucide-react";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

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
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import type { ReturnableLine } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { returnItems, returnValue, useOrderReturns, useRecordReturn } from "@/lib/dashboard/returns";
import { formatMoney } from "@/lib/format/money";

import { LoadingBlock } from "../common/States";

const copy = ar.dashboard.returns;

/** Today's local date as `Y-m-d`, the default of the return date field. */
function today(): string {
  const now = new Date();

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

/**
 * Records a return of a delivered order (BR-R01..R04): one stepper per line capped at what it can still take back
 * (BR-R02), the reason and the date. When the server answers `refund_required`, the amount to pay back is shown
 * and the merchant confirms the cash refund before sending again.
 */
export function ReturnDialog({ orderNumber }: { orderNumber: number }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {copy.record}
      </Button>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{copy.recordTitle}</DialogTitle>
          <DialogDescription>{copy.recordBody}</DialogDescription>
        </DialogHeader>
        {open && <ReturnForm orderNumber={orderNumber} onDone={() => setOpen(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function ReturnForm({ orderNumber, onDone }: { orderNumber: number; onDone: () => void }) {
  const returns = useOrderReturns(orderNumber);
  const record = useRecordReturn(orderNumber);
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [reason, setReason] = useState("");
  const [date, setDate] = useState(today);
  const [refundAmount, setRefundAmount] = useState<string | null>(null);
  const [refundConfirmed, setRefundConfirmed] = useState(false);
  const [refundTo, setRefundTo] = useState<"cash" | "credit">("cash");
  const [error, setError] = useState<string | null>(null);

  if (returns.isError) {
    return <p role="alert">{messageFor(returns.error)}</p>;
  }

  if (returns.data === undefined) {
    return <LoadingBlock rows={3} />;
  }

  const lines = returns.data.returnable;

  if (lines.every((line) => line.returnable === 0)) {
    return <p className="text-muted-foreground text-sm">{copy.nothingReturnable}</p>;
  }

  const set = (line: ReturnableLine, quantity: number) =>
    setQuantities((current) => ({
      ...current,
      [line.order_item_id]: Math.max(0, Math.min(line.returnable, quantity)),
    }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const items = returnItems(quantities);

    if (items.length === 0) {
      setError(copy.nothingSelected);
      return;
    }

    if (reason.trim() === "") {
      setError(ar.dashboard.reasonRequired);
      return;
    }

    if (refundAmount !== null && !refundConfirmed) {
      setError(copy.refundConfirmRequired);
      return;
    }

    setError(null);
    record.mutate(
      {
        reason: reason.trim(),
        items,
        ...(date !== today() && date !== "" ? { returned_at: `${date}T12:00:00` } : {}),
        ...(refundAmount !== null ? { refund: true, refund_to: refundTo } : {}),
      },
      {
        onSuccess: () => {
          toast.success(copy.recorded);
          onDone();
        },
        onError: (failure) => {
          if (isApiError(failure, "refund_required") && typeof failure.details?.refund_amount === "string") {
            setRefundAmount(failure.details.refund_amount);
            return;
          }

          setError(messageFor(failure));
        },
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-3">
      <ul className="flex flex-col divide-y rounded-md border" data-testid="return-lines">
        {lines.map((line) => {
          const quantity = quantities[line.order_item_id] ?? 0;

          return (
            <li key={line.order_item_id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
              <span className="flex min-w-0 flex-col">
                <span className="font-medium">{line.product_name}</span>
                <span className="text-muted-foreground text-xs">
                  <bdi>{line.sku_code}</bdi> · {copy.ordered}: {ar.orders.quantityOf(line.ordered, line.unit)} ·{" "}
                  {copy.alreadyReturned}: {line.returned} · {formatMoney(line.unit_price)}
                </span>
              </span>
              <span className="flex items-center gap-1">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  aria-label={`${copy.returnQuantity} − ${line.product_name}`}
                  disabled={quantity === 0}
                  onClick={() => set(line, quantity - 1)}
                >
                  <MinusIcon />
                </Button>
                <Input
                  aria-label={`${copy.returnQuantity} ${line.product_name}`}
                  dir="ltr"
                  inputMode="numeric"
                  className="w-16 text-center"
                  value={String(quantity)}
                  disabled={line.returnable === 0}
                  onChange={(event) => set(line, Number(event.target.value.replace(/\D/g, "")) || 0)}
                />
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  aria-label={`${copy.returnQuantity} + ${line.product_name}`}
                  disabled={quantity >= line.returnable}
                  onClick={() => set(line, quantity + 1)}
                >
                  <PlusIcon />
                </Button>
              </span>
            </li>
          );
        })}
      </ul>
      <p className="text-sm font-medium" data-testid="return-value">
        {copy.total(formatMoney(returnValue(lines, quantities)))}
      </p>
      <Field>
        <FieldLabel htmlFor="return-reason">{copy.reason}</FieldLabel>
        <Textarea
          id="return-reason"
          rows={2}
          maxLength={300}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />
      </Field>
      <Field>
        <FieldLabel htmlFor="return-date">{copy.returnedAt}</FieldLabel>
        <Input
          id="return-date"
          type="date"
          max={today()}
          className="w-44"
          value={date}
          onChange={(event) => setDate(event.target.value)}
        />
      </Field>
      {refundAmount !== null && (
        <Alert>
          <AlertDescription className="flex flex-col gap-2">
            <span>{copy.refundNeeded(formatMoney(refundAmount))}</span>
            <span className="text-xs font-medium">{copy.refundHow}</span>
            <RadioGroup
              dir="rtl"
              value={refundTo}
              onValueChange={(value) => setRefundTo(value === "credit" ? "credit" : "cash")}
              className="gap-2"
            >
              <span className="flex items-center gap-2">
                <RadioGroupItem id="return-refund-cash" value="cash" />
                <Label htmlFor="return-refund-cash">{copy.refundCash}</Label>
              </span>
              <span className="flex items-center gap-2">
                <RadioGroupItem id="return-refund-credit" value="credit" />
                <Label htmlFor="return-refund-credit">{copy.refundCredit}</Label>
              </span>
            </RadioGroup>
            <span className="flex items-center gap-2">
              <Checkbox
                id="return-refund"
                checked={refundConfirmed}
                onCheckedChange={(checked) => setRefundConfirmed(checked === true)}
              />
              <Label htmlFor="return-refund">{copy.refundConfirm}</Label>
            </span>
          </AlertDescription>
        </Alert>
      )}
      {error !== null && <FieldError role="alert">{error}</FieldError>}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>
          {ar.cancel}
        </Button>
        <Button type="submit" disabled={record.isPending}>
          {record.isPending ? ar.saving : copy.record}
        </Button>
      </DialogFooter>
    </form>
  );
}
