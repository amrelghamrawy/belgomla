"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field";
import type { DashboardOrderDetail } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useUpdateDeliveryAddress } from "@/lib/dashboard/orders";
import { phoneField } from "@/lib/forms/phone";
import { applyServerErrors } from "@/lib/forms/server-errors";

const copy = ar.dashboard.orders;
const optional = (max: number) => z.string().trim().max(max, ar.validation.max(max));
const required = (max: number) => optional(max).min(1, ar.validation.required);

// Mirrors UpdateDeliveryAddressRequest.
const schema = z.object({
  label: optional(50),
  governorate: required(50),
  city: required(80),
  address_line: required(300),
  landmark: optional(200),
  name: optional(150),
  phone: z.union([z.literal(""), phoneField()]),
});

type Values = z.infer<typeof schema>;
const FIELDS = ["label", "governorate", "city", "address_line", "landmark", "name", "phone"] as const;

/** D9: edit the order's delivery snapshot before it leaves; 409 address_locked is shown if it already has. */
export function DeliveryAddressDialog({ order, onClose }: { order: DashboardOrderDetail; onClose: () => void }) {
  const update = useUpdateDeliveryAddress(order.number);
  const [error, setError] = useState<string | null>(null);
  const address = order.delivery.address;
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      label: address.label,
      governorate: address.governorate,
      city: address.city,
      address_line: address.address_line,
      landmark: address.landmark ?? "",
      name: order.delivery.name,
      phone: "",
    },
  });
  const errors = form.formState.errors;
  const empty = (value: string) => (value === "" ? null : value);

  const submit = form.handleSubmit((values) => {
    setError(null);
    update.mutate(
      {
        label: empty(values.label),
        governorate: values.governorate,
        city: values.city,
        address_line: values.address_line,
        landmark: empty(values.landmark),
        name: empty(values.name),
        phone: empty(values.phone),
      },
      {
        onSuccess: () => {
          toast.success(copy.addressSaved);
          onClose();
        },
        onError: (failure) => {
          if (!applyServerErrors(failure, FIELDS, form.setError)) {
            setError(messageFor(failure));
          }
        },
      },
    );
  });

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{copy.addressTitle}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <TextField
            id="delivery-name"
            label={copy.recipient}
            registration={form.register("name")}
            error={errors.name}
          />
          <TextField
            id="delivery-phone"
            label={copy.recipientPhone}
            dir="ltr"
            inputMode="tel"
            placeholder={order.delivery.phone}
            registration={form.register("phone")}
            error={errors.phone}
          />
          <TextField
            id="delivery-label"
            label={ar.addresses.label}
            registration={form.register("label")}
            error={errors.label}
          />
          <TextField
            id="delivery-governorate"
            label={ar.addresses.governorate}
            registration={form.register("governorate")}
            error={errors.governorate}
          />
          <TextField
            id="delivery-city"
            label={ar.addresses.city}
            registration={form.register("city")}
            error={errors.city}
          />
          <TextField
            id="delivery-line"
            label={ar.addresses.addressLine}
            registration={form.register("address_line")}
            error={errors.address_line}
          />
          <TextField
            id="delivery-landmark"
            label={ar.addresses.landmark}
            registration={form.register("landmark")}
            error={errors.landmark}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={update.isPending}>
              {update.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
