"use client";

import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import type { FieldDefinition } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type CustomFieldDrafts, visibleDefinitions } from "@/lib/dashboard/custom-fields";

import { LabelText } from "../common/LabelText";
import { NativeSelect } from "../common/NativeSelect";

const copy = ar.dashboard.productForm;

type Props = {
  definitions: readonly FieldDefinition[];
  values: CustomFieldDrafts;
  onChange: (key: string, value: string) => void;
  errors: Record<string, string | undefined>;
};

/**
 * The product's custom fields, rendered from the tenant's definitions: text and number inputs, a select of
 * the field's options, yes/no for booleans. Archived fields that still hold a value show read-only, and an
 * archived option stays selectable only while it is the current value (CustomFieldValidator).
 */
export function CustomFieldInputs({ definitions, values, onChange, errors }: Props) {
  const shown = visibleDefinitions(definitions, values);

  if (shown.length === 0) {
    return <p className="text-muted-foreground text-sm">{copy.noFields}</p>;
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {shown.map((definition) => {
        const id = `custom-${definition.key}`;
        const value = values[definition.key] ?? "";
        const error = errors[definition.key];
        const archived = definition.status === "archived";

        return (
          <Field key={definition.key} data-invalid={error !== undefined}>
            <FieldLabel htmlFor={id}>
              <LabelText text={definition.label} need={definition.is_required ? "required" : "optional"} />
            </FieldLabel>
            {definition.type === "select" ? (
              <NativeSelect
                id={id}
                value={value}
                disabled={archived}
                aria-invalid={error !== undefined}
                onChange={(event) => onChange(definition.key, event.target.value)}
              >
                <option value="">{copy.chooseOption}</option>
                {[...definition.options]
                  .filter((option) => option.status === "active" || String(option.id) === value)
                  .sort((a, b) => a.sort_order - b.sort_order)
                  .map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
              </NativeSelect>
            ) : definition.type === "boolean" ? (
              <NativeSelect
                id={id}
                value={value}
                disabled={archived}
                aria-invalid={error !== undefined}
                onChange={(event) => onChange(definition.key, event.target.value)}
              >
                <option value="">{copy.chooseOption}</option>
                <option value="true">{ar.dashboard.yes}</option>
                <option value="false">{ar.dashboard.no}</option>
              </NativeSelect>
            ) : (
              <Input
                id={id}
                type={definition.type === "number" ? "number" : "text"}
                inputMode={definition.type === "number" ? "decimal" : undefined}
                maxLength={definition.type === "text" ? 500 : undefined}
                value={value}
                disabled={archived}
                aria-invalid={error !== undefined}
                onChange={(event) => onChange(definition.key, event.target.value)}
              />
            )}
            {error !== undefined && <FieldError>{error}</FieldError>}
          </Field>
        );
      })}
    </div>
  );
}
