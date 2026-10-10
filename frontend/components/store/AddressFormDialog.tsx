"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import type { Address } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { phoneField } from "@/lib/forms/phone";
import { useSaveAddress } from "@/lib/store/addresses";

const optional = (max: number) => z.string().trim().max(max, ar.validation.max(max));
const required = (max: number) => optional(max).min(1, ar.validation.required);

// Mirrors SaveAddressRequest (BR-CU03).
export const addressSchema = z.object({
  label: required(50),
  governorate: required(50),
  city: required(80),
  address_line: required(300),
  landmark: optional(200),
  phone: z.union([z.literal(""), phoneField()]),
  is_default: z.boolean(),
});

type AddressValues = z.infer<typeof addressSchema>;

const FIELDS = ["label", "governorate", "city", "address_line", "landmark", "phone", "is_default"] as const;

function valuesOf(address: Address | null, makeDefault: boolean): AddressValues {
  return {
    label: address?.label ?? "",
    governorate: address?.governorate ?? "",
    city: address?.city ?? "",
    address_line: address?.address_line ?? "",
    landmark: address?.landmark ?? "",
    phone: "",
    is_default: address?.is_default ?? makeDefault,
  };
}

type AddressFormDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The address to edit, or null to add one. */
  address: Address | null;
  /** The first address becomes the default. */
  makeDefault?: boolean;
  onSaved?: (address: Address) => void;
};

/** Add or edit a delivery address in a dialog; used on /account/addresses and at checkout. */
export function AddressFormDialog({
  open,
  onOpenChange,
  address,
  makeDefault = false,
  onSaved,
}: AddressFormDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{address === null ? ar.addresses.add : ar.addresses.edit}</DialogTitle>
          <DialogDescription className="sr-only">{ar.addresses.labelHint}</DialogDescription>
        </DialogHeader>
        {/* Mounted only while open: each opening starts from the address (or blank), and data loading behind the
            dialog never resets what is being typed. */}
        <AddressForm
          address={address}
          makeDefault={makeDefault}
          onSaved={(saved) => {
            onOpenChange(false);
            onSaved?.(saved);
          }}
        />
      </DialogContent>
    </Dialog>
  );
}

function AddressForm({
  address,
  makeDefault,
  onSaved,
}: {
  address: Address | null;
  makeDefault: boolean;
  onSaved: (address: Address) => void;
}) {
  const save = useSaveAddress();
  const form = useForm<AddressValues>({
    resolver: zodResolver(addressSchema),
    defaultValues: valuesOf(address, makeDefault),
  });

  const submit = form.handleSubmit((values) =>
    save.mutate(
      {
        id: address?.id,
        ...values,
        landmark: values.landmark === "" ? null : values.landmark,
        phone: values.phone === "" ? null : values.phone,
      },
      {
        onSuccess: (saved) => {
          toast.success(ar.addresses.saved);
          onSaved(saved);
        },
        onError: (error) => {
          if (!applyServerErrors(error, FIELDS, form.setError)) {
            form.setError("root", { message: messageFor(error) });
          }
        },
      },
    ),
  );

  const { errors } = form.formState;

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup>
        <TextField
          id="address-label"
          label={ar.addresses.label}
          placeholder={ar.addresses.labelHint}
          registration={form.register("label")}
          error={errors.label}
        />
        <TextField
          id="address-governorate"
          label={ar.addresses.governorate}
          autoComplete="address-level1"
          registration={form.register("governorate")}
          error={errors.governorate}
        />
        <TextField
          id="address-city"
          label={ar.addresses.city}
          autoComplete="address-level2"
          registration={form.register("city")}
          error={errors.city}
        />
        <TextField
          id="address-line"
          label={ar.addresses.addressLine}
          autoComplete="street-address"
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
          type="tel"
          dir="ltr"
          inputMode="tel"
          registration={form.register("phone")}
          error={errors.phone}
        />
        <Controller
          control={form.control}
          name="is_default"
          render={({ field }) => (
            <Field orientation="horizontal">
              <Checkbox
                id="address-default"
                checked={field.value}
                onCheckedChange={(checked) => field.onChange(checked === true)}
              />
              <FieldLabel htmlFor="address-default" className="font-normal">
                {ar.addresses.makeDefault}
              </FieldLabel>
            </Field>
          )}
        />
        {errors.root?.message !== undefined && <FieldError role="alert">{errors.root.message}</FieldError>}
        <Button type="submit" size="lg" className="h-11 w-full" disabled={save.isPending}>
          {save.isPending ? ar.saving : ar.save}
        </Button>
      </FieldGroup>
    </form>
  );
}
