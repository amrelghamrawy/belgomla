"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { type FormEvent, useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Textarea } from "@/components/ui/textarea";
import type { CategoryKind, FieldDefinition, FieldOption } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  FIELD_TYPES,
  useArchiveField,
  useArchiveFieldOption,
  useFields,
  useSaveField,
  useSaveFieldOption,
} from "@/lib/dashboard/catalog";
import { applyServerErrors } from "@/lib/forms/server-errors";

import { NativeSelect } from "../common/NativeSelect";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.fields;
const KINDS: readonly CategoryKind[] = ["books", "stationery", "toys", "general"];

/** Mirrors StoreFieldRequest / UpdateFieldRequest. `kind` "" stands for every section (null on the API, D69). */
const schema = z.object({
  key: z
    .string()
    .trim()
    .max(50)
    .regex(/^[a-z][a-z0-9_]*$/, copy.keyHint),
  label: z.string().trim().min(1, ar.validation.required).max(100),
  type: z.enum(["text", "number", "select", "boolean"]),
  kind: z.enum(["", "books", "stationery", "toys", "general"]),
  is_required: z.boolean(),
  show_on_storefront: z.boolean(),
  is_filterable: z.boolean(),
  filter_order: z.string().regex(/^\d{1,6}$/, ar.dashboard.productForm.invalidInteger),
  sort_order: z.string().regex(/^\d{1,6}$/, ar.dashboard.productForm.invalidInteger),
  options: z.string(),
});

type FieldForm = z.infer<typeof schema>;

const FORM_FIELDS = ["key", "label", "type", "kind", "filter_order", "sort_order", "options"] as const;

/** Custom field definitions: a table, a create/edit dialog, and the options editor of select fields. */
export function FieldsPage() {
  const fields = useFields();
  const [editing, setEditing] = useState<FieldDefinition | "new" | null>(null);
  const [optionsOf, setOptionsOf] = useState<number | null>(null);
  const optionsField = fields.data?.find((field) => field.id === optionsOf);

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={<Button onClick={() => setEditing("new")}>{copy.add}</Button>}
      />
      {fields.isError && <ErrorBlock error={fields.error} onRetry={() => void fields.refetch()} />}
      {fields.isPending && <LoadingBlock />}
      {fields.data?.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {fields.data !== undefined && fields.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.label}</TableHead>
                <TableHead>{copy.key}</TableHead>
                <TableHead>{copy.type}</TableHead>
                <TableHead>{copy.kind}</TableHead>
                <TableHead>{copy.flags}</TableHead>
                <TableHead>{copy.filterOrder}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {fields.data.map((field) => (
                <TableRow key={field.id} data-testid={`field-${field.key}`}>
                  <TableCell className="font-medium">
                    {field.label}
                    {field.status === "archived" && (
                      <Badge variant="secondary" className="ms-2">
                        {ar.dashboard.archived}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    <bdi>{field.key}</bdi>
                  </TableCell>
                  <TableCell>{copy.types[field.type]}</TableCell>
                  <TableCell>{field.kind === null ? copy.kindAll : ar.catalog.kinds[field.kind]}</TableCell>
                  <TableCell className="text-muted-foreground text-xs">
                    {[
                      field.is_required && copy.isRequired,
                      field.show_on_storefront && copy.showOnStorefront,
                      field.is_filterable && copy.isFilterable,
                    ]
                      .filter(Boolean)
                      .join("، ")}
                  </TableCell>
                  <TableCell>{field.is_filterable ? field.filter_order : ar.dashboard.none}</TableCell>
                  <TableCell className="flex flex-wrap gap-1">
                    <Button variant="ghost" size="sm" onClick={() => setEditing(field)}>
                      {ar.dashboard.edit}
                    </Button>
                    {field.type === "select" && (
                      <Button variant="ghost" size="sm" onClick={() => setOptionsOf(field.id)}>
                        {copy.options} ({field.options.filter((option) => option.status === "active").length})
                      </Button>
                    )}
                    {field.status === "active" && <ArchiveFieldButton id={field.id} />}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {editing !== null && <FieldDialog field={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
      {optionsField !== undefined && <OptionsDialog field={optionsField} onClose={() => setOptionsOf(null)} />}
    </>
  );
}

function ArchiveFieldButton({ id }: { id: number }) {
  const archive = useArchiveField();

  return (
    <Button
      variant="ghost"
      size="sm"
      disabled={archive.isPending}
      onClick={() => archive.mutate(id, { onError: (error) => toast.error(messageFor(error)) })}
    >
      {ar.dashboard.archive}
    </Button>
  );
}

/** Create or edit a definition. The key and the initial options are create-only; the type locks once used. */
export function FieldDialog({ field, onClose }: { field: FieldDefinition | null; onClose: () => void }) {
  const save = useSaveField();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<FieldForm>({
    resolver: zodResolver(field === null ? schema : schema.extend({ key: z.string() })),
    defaultValues: {
      key: field?.key ?? "",
      label: field?.label ?? "",
      type: field?.type ?? "select",
      kind: field?.kind ?? "",
      is_required: field?.is_required ?? false,
      show_on_storefront: field?.show_on_storefront ?? true,
      is_filterable: field?.is_filterable ?? false,
      filter_order: String(field?.filter_order ?? 0),
      sort_order: String(field?.sort_order ?? 0),
      options: "",
    },
  });
  const type = useWatch({ control: form.control, name: "type" });
  const errors = form.formState.errors;

  const submit = form.handleSubmit((values) => {
    setError(null);
    const options = values.options
      .split("\n")
      .map((line) => line.trim())
      .filter((line) => line !== "");

    save.mutate(
      {
        id: field?.id,
        key: field === null ? values.key : undefined,
        label: values.label,
        type: values.type,
        kind: values.kind === "" ? null : values.kind,
        is_required: values.is_required,
        show_on_storefront: values.show_on_storefront,
        is_filterable: values.is_filterable,
        filter_order: Number(values.filter_order),
        sort_order: Number(values.sort_order),
        options: field === null && values.type === "select" && options.length > 0 ? options : undefined,
      },
      {
        onSuccess: () => {
          toast.success(ar.dashboard.saved);
          onClose();
        },
        onError: (failure) => {
          if (!applyServerErrors(failure, FORM_FIELDS, form.setError)) {
            setError(messageFor(failure));
          }
        },
      },
    );
  });

  const flag = (name: "is_required" | "show_on_storefront" | "is_filterable", label: string) => (
    <Controller
      control={form.control}
      name={name}
      render={({ field: input }) => (
        <div className="flex items-center gap-2">
          <Checkbox
            id={`field-${name}`}
            checked={input.value}
            onCheckedChange={(checked) => input.onChange(checked === true)}
          />
          <Label htmlFor={`field-${name}`}>{label}</Label>
        </div>
      )}
    />
  );

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{field === null ? copy.addTitle : copy.editTitle}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <Field data-invalid={errors.label !== undefined}>
            <FieldLabel htmlFor="field-label">{copy.label}</FieldLabel>
            <Input
              id="field-label"
              maxLength={100}
              aria-invalid={errors.label !== undefined}
              {...form.register("label")}
            />
            <FieldError errors={[errors.label]} />
          </Field>
          <Field data-invalid={errors.key !== undefined}>
            <FieldLabel htmlFor="field-key">{copy.key}</FieldLabel>
            <Input
              id="field-key"
              dir="ltr"
              className="text-start"
              maxLength={50}
              disabled={field !== null}
              aria-invalid={errors.key !== undefined}
              {...form.register("key")}
            />
            <p className="text-muted-foreground text-xs">{copy.keyHint}</p>
            <FieldError errors={[errors.key]} />
          </Field>
          <Field data-invalid={errors.type !== undefined}>
            <FieldLabel htmlFor="field-type">{copy.type}</FieldLabel>
            <NativeSelect id="field-type" {...form.register("type")}>
              {FIELD_TYPES.map((value) => (
                <option key={value} value={value}>
                  {copy.types[value]}
                </option>
              ))}
            </NativeSelect>
            {field !== null && (
              <Alert>
                <AlertDescription>{copy.typeLocked}</AlertDescription>
              </Alert>
            )}
            <FieldError errors={[errors.type]} />
          </Field>
          <Field data-invalid={errors.kind !== undefined}>
            <FieldLabel htmlFor="field-kind">{copy.kind}</FieldLabel>
            <NativeSelect id="field-kind" {...form.register("kind")}>
              <option value="">{copy.kindAll}</option>
              {KINDS.map((value) => (
                <option key={value} value={value}>
                  {ar.catalog.kinds[value]}
                </option>
              ))}
            </NativeSelect>
            <p className="text-muted-foreground text-xs">{copy.kindHint}</p>
            <FieldError errors={[errors.kind]} />
          </Field>
          <div className="flex flex-wrap gap-4">
            {flag("is_required", copy.isRequired)}
            {flag("show_on_storefront", copy.showOnStorefront)}
            {flag("is_filterable", copy.isFilterable)}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field>
              <FieldLabel htmlFor="field-filter-order">{copy.filterOrder}</FieldLabel>
              <Input
                id="field-filter-order"
                type="number"
                min={0}
                inputMode="numeric"
                {...form.register("filter_order")}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="field-sort-order">{copy.sortOrder}</FieldLabel>
              <Input id="field-sort-order" type="number" min={0} inputMode="numeric" {...form.register("sort_order")} />
            </Field>
          </div>
          {field === null && type === "select" && (
            <Field>
              <FieldLabel htmlFor="field-options">{copy.options}</FieldLabel>
              <Textarea id="field-options" rows={4} placeholder={copy.optionsHint} {...form.register("options")} />
              <FieldError errors={[errors.options]} />
            </Field>
          )}
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** The options of a select field: rename, reorder by number, archive, add. */
function OptionsDialog({ field, onClose }: { field: FieldDefinition; onClose: () => void }) {
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {copy.options}: {field.label}
          </DialogTitle>
        </DialogHeader>
        <ul className="flex flex-col gap-2">
          {[...field.options]
            .sort((a, b) => a.sort_order - b.sort_order)
            .map((option) => (
              <OptionRow key={`${option.id}-${option.label}-${option.sort_order}`} fieldId={field.id} option={option} />
            ))}
        </ul>
        <OptionRow fieldId={field.id} />
      </DialogContent>
    </Dialog>
  );
}

function OptionRow({ fieldId, option }: { fieldId: number; option?: FieldOption }) {
  const save = useSaveFieldOption(fieldId);
  const archive = useArchiveFieldOption(fieldId);
  const [label, setLabel] = useState(option?.label ?? "");
  const [order, setOrder] = useState(String(option?.sort_order ?? 0));
  const [error, setError] = useState<string | null>(null);
  const archived = option?.status === "archived";

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (label.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    setError(null);
    save.mutate(
      { id: option?.id, label: label.trim(), sort_order: Number(order) || 0 },
      {
        onSuccess: () => {
          if (option === undefined) {
            setLabel("");
          }
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  const body = (
    <form onSubmit={submit} noValidate className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <Input
          aria-label={copy.optionLabel}
          value={label}
          maxLength={100}
          disabled={archived}
          placeholder={option === undefined ? copy.addOption : undefined}
          onChange={(event) => setLabel(event.target.value)}
        />
        <Input
          aria-label={copy.sortOrder}
          type="number"
          min={0}
          className="w-20"
          value={order}
          disabled={archived}
          onChange={(event) => setOrder(event.target.value)}
        />
        {archived ? (
          <Badge variant="secondary">{ar.dashboard.archived}</Badge>
        ) : (
          <Button
            type="submit"
            size="sm"
            variant={option === undefined ? "default" : "outline"}
            disabled={save.isPending}
          >
            {option === undefined ? copy.addOption : ar.save}
          </Button>
        )}
        {option !== undefined && !archived && (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            disabled={archive.isPending}
            onClick={() => archive.mutate(option.id, { onError: (failure) => setError(messageFor(failure)) })}
          >
            {ar.dashboard.archive}
          </Button>
        )}
      </div>
      {error !== null && <FieldError role="alert">{error}</FieldError>}
    </form>
  );

  return option === undefined ? <div className="border-t pt-3">{body}</div> : <li>{body}</li>;
}
