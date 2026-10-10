import type { ComponentProps } from "react";
import type { FieldError as FormFieldError, UseFormRegisterReturn } from "react-hook-form";

import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

type TextFieldProps = {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: FormFieldError;
} & Omit<ComponentProps<typeof Input>, "id" | keyof UseFormRegisterReturn>;

/**
 * A labelled input wired to react-hook-form, with its error under it. Latin-only values (email, phone,
 * password) pass `dir="ltr"`; `text-start` keeps them aligned with the Arabic labels (rules/03 §3).
 */
export function TextField({ id, label, registration, error, className, ...input }: TextFieldProps) {
  return (
    <Field data-invalid={error !== undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Input
        id={id}
        aria-invalid={error !== undefined}
        className={`h-11 text-start ${className ?? ""}`}
        {...input}
        {...registration}
      />
      <FieldError errors={[error]} />
    </Field>
  );
}
