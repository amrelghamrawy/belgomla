"use client";

import { type FormEvent, type ReactNode, useState } from "react";

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
import { Textarea } from "@/components/ui/textarea";
import { ar, messageFor } from "@/lib/copy/ar";

type Props = {
  trigger: (open: () => void) => ReactNode;
  title: string;
  description?: string;
  confirmLabel: string;
  destructive?: boolean;
  /** Resolves on success (the dialog closes); a rejection shows its message under the field. */
  onSubmit: (reason: string) => Promise<unknown>;
  maxLength?: number;
};

/** A dialog that asks for a required reason: cancel an order, void a collection, block a customer. */
export function ReasonDialog({
  trigger,
  title,
  description,
  confirmLabel,
  destructive = false,
  onSubmit,
  maxLength = 300,
}: Props) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();

    if (reason.trim() === "") {
      setError(ar.dashboard.reasonRequired);
      return;
    }

    setError(null);
    setPending(true);

    try {
      await onSubmit(reason.trim());
      setOpen(false);
      setReason("");
    } catch (failure) {
      setError(messageFor(failure));
    } finally {
      setPending(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        setError(null);
      }}
    >
      {trigger(() => setOpen(true))}
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description !== undefined && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <FieldLabel htmlFor="reason-dialog-reason">{ar.dashboard.reason}</FieldLabel>
          <Textarea
            id="reason-dialog-reason"
            value={reason}
            maxLength={maxLength}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={error !== null}
            rows={3}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              {ar.cancel}
            </Button>
            <Button type="submit" variant={destructive ? "destructive" : "default"} disabled={pending}>
              {pending ? ar.saving : confirmLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
