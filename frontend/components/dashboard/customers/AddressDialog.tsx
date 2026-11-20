"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { FieldLabel } from "@/components/ui/field";
import type { DashboardCustomer } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import type { CustomerAddressInput } from "@/lib/dashboard/customers";
import { addressSchema, type AddressFormValues } from "@/lib/forms/address";

// A customer's address as the dashboard edits it (BR-CU03): the customer page's list and the new-order form (BR-O11).

export function addressInput(address: NonNullable<DashboardCustomer["addresses"]>[number]): CustomerAddressInput {
  return {
    id: address.id,
    label: address.label,
    governorate: address.governorate,
    city: address.city,
    address_line: address.address_line,
    landmark: address.landmark,
    phone: address.phone,
    is_default: address.is_default,
  };
}

export function AddressDialog({
  address,
  onClose,
  onSave,
}: {
  address: CustomerAddressInput | null;
  onClose: () => void;
  onSave: (address: CustomerAddressInput) => void;
}) {
  const form = useForm<AddressFormValues>({
    resolver: zodResolver(addressSchema),
    defaultValues: {
      label: address?.label ?? "",
      governorate: address?.governorate ?? "",
      city: address?.city ?? "",
      address_line: address?.address_line ?? "",
      landmark: address?.landmark ?? "",
      phone: "",
      is_default: address?.is_default ?? false,
    },
  });
  const errors = form.formState.errors;

  const submit = form.handleSubmit((values) =>
    onSave({
      id: address?.id,
      ...values,
      landmark: values.landmark === "" ? null : values.landmark,
      phone: values.phone === "" ? (address?.phone ?? null) : values.phone,
    }),
  );

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{address === null ? ar.addresses.add : ar.addresses.edit}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <TextField
            id="address-label"
            label={ar.addresses.label}
            registration={form.register("label")}
            error={errors.label}
          />
          <TextField
            id="address-governorate"
            label={ar.addresses.governorate}
            registration={form.register("governorate")}
            error={errors.governorate}
          />
          <TextField
            id="address-city"
            label={ar.addresses.city}
            registration={form.register("city")}
            error={errors.city}
          />
          <TextField
            id="address-line"
            label={ar.addresses.addressLine}
            registration={form.register("address_line")}
            error={errors.address_line}
          />
          <TextField
            id="address-landmark"
            label={ar.addresses.landmark}
            registration={form.register("landmark")}
            error={errors.landmark}
          />
          <TextField
            id="address-phone"
            label={ar.addresses.phone}
            dir="ltr"
            inputMode="tel"
            registration={form.register("phone")}
            error={errors.phone}
          />
          <Controller
            control={form.control}
            name="is_default"
            render={({ field }) => (
              <div className="flex items-center gap-2">
                <Checkbox
                  id="address-default"
                  checked={field.value}
                  onCheckedChange={(checked) => field.onChange(checked === true)}
                />
                <FieldLabel htmlFor="address-default">{ar.addresses.makeDefault}</FieldLabel>
              </div>
            )}
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit">{ar.dashboard.confirm}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
