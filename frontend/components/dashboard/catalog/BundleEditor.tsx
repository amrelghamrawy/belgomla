"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowDownIcon, ArrowUpIcon, Trash2Icon } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Controller, type Path, useFieldArray, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import type { DashboardCategory, DashboardProduct, ProductStatus, Unit } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  addComponent,
  bundleBody,
  bundleDefaults,
  type BundleFormValues,
  bundleSchema,
  bundleServerFields,
  bundleTotals,
  MAX_COMPONENTS,
  useSaveBundle,
} from "@/lib/dashboard/bundles";
import { useDashboardCategories, useDashboardProduct, useSetProductStatus, useUnits } from "@/lib/dashboard/catalog";
import { activeOnly } from "@/lib/dashboard/category-tree";
import { formatMoney } from "@/lib/format/money";

import { CategoryPicker } from "../common/CategoryPicker";
import { LabelText } from "../common/LabelText";
import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { BundleComponentPicker } from "./BundleComponentPicker";

const copy = ar.dashboard.bundles;
const BUNDLE_STATUSES = ["active", "inactive"] as const;

/** Loads what the bundle form needs (the bundle on edit, units, categories) and renders it (BR-P15, D93). */
export function BundleEditor({ productId }: { productId?: number }) {
  const product = useDashboardProduct(productId);
  const units = useUnits();
  const categories = useDashboardCategories();
  const failed = [product, units, categories].find((query) => query.isError);

  if (failed !== undefined) {
    return <ErrorBlock error={failed.error} onRetry={() => void failed.refetch()} />;
  }

  if (units.data === undefined || categories.data === undefined || (productId !== undefined && !product.data)) {
    return <LoadingBlock rows={8} />;
  }

  return (
    <BundleForm
      // Remount on every saved change so the form never holds an older bundle than the server's.
      key={product.data ? `${product.data.id}-${product.data.updated_at}` : "new"}
      product={product.data ?? null}
      units={units.data}
      categories={categories.data}
    />
  );
}

type FormProps = { product: DashboardProduct | null; units: Unit[]; categories: DashboardCategory[] };

/**
 * The bundle form: name, category, code, unit, MOQ, description, the components (picked by product search, a unit of
 * an active SKU each, with a quantity, reordered or removed) and the pricing, a fixed price or the components' sum
 * less a %, with the sum, price and saving shown live. On edit: the stock of each component and the bundles that can
 * be made now; an inactive bundle (a generated grade set starts so) says it is hidden until activated.
 */
export function BundleForm({ product, units, categories }: FormProps) {
  const router = useRouter();
  const save = useSaveBundle();
  const creating = product === null;
  const [error, setError] = useState<string | null>(null);
  const form = useForm<BundleFormValues>({
    resolver: zodResolver(bundleSchema),
    defaultValues: bundleDefaults(product),
  });
  const errors = form.formState.errors;
  const rows = useFieldArray({ control: form.control, name: "components" });
  const components = useWatch({ control: form.control, name: "components" });
  const pricing = useWatch({ control: form.control, name: "pricing" });
  const totals = bundleTotals(components, pricing);
  const storedUnit = product?.sku?.units?.[0]?.unit?.id;
  const activeUnits = units.filter((unit) => unit.status === "active" || unit.id === storedUnit);
  const componentsError = errors.components?.root?.message ?? errors.components?.message;

  const showServerErrors = (failure: unknown) => {
    if (isApiError(failure, "duplicate_code")) {
      form.setError("code", { type: "server", message: failure.message });
      return;
    }

    if (isApiError(failure, "validation_failed")) {
      const unmapped: string[] = [];
      let mapped = false;

      for (const field of bundleServerFields(form.getValues("components").length)) {
        const message = failure.fieldMessage(field);

        if (message !== null) {
          form.setError(field as Path<BundleFormValues>, { type: "server", message });
          mapped = true;
        }
      }

      // Keys without an input on this form (custom fields, …) go to the page alert.
      for (const [key, messages] of Object.entries(failure.details ?? {})) {
        if (!bundleServerFields(form.getValues("components").length).includes(key) && Array.isArray(messages)) {
          unmapped.push(String(messages[0]));
        }
      }

      if (unmapped.length > 0) {
        setError(unmapped.join("، "));
        return;
      }

      if (mapped) {
        return;
      }
    }

    setError(messageFor(failure));
  };

  const submit = form.handleSubmit((valid) => {
    setError(null);
    save.mutate(
      { id: product?.id, body: bundleBody(valid, product) },
      {
        onSuccess: (saved) => {
          toast.success(creating ? copy.created : copy.saved);

          if (creating) {
            router.replace(`/dashboard/bundles/${saved.id}`);
          }
        },
        onError: showServerErrors,
      },
    );
  });

  const text = (name: "name" | "code" | "moq", label: string, need: "required" | "optional", ltr = false) => (
    <Field data-invalid={errors[name] !== undefined}>
      <FieldLabel htmlFor={`bundle-${name}`}>
        <LabelText text={label} need={need} />
      </FieldLabel>
      <Input
        id={`bundle-${name}`}
        dir={ltr ? "ltr" : undefined}
        className={ltr ? "text-start" : undefined}
        inputMode={name === "moq" ? "numeric" : undefined}
        aria-invalid={errors[name] !== undefined}
        {...form.register(name)}
      />
      <FieldError errors={[errors[name]]} />
    </Field>
  );

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 pb-20 md:pb-0">
      <PageHeader
        title={creating ? copy.newTitle : product.name}
        description={
          creating ? undefined : (
            <span className="flex flex-wrap items-center gap-2">
              <Badge variant="secondary">{copy.badge}</Badge>
              {product.bundle && (
                <span data-testid="bundle-availability">{copy.availability(product.bundle.availability)}</span>
              )}
            </span>
          )
        }
        actions={
          <>
            {!creating && <BundleStatusControl product={product} />}
            <Button type="submit" className="hidden md:inline-flex" disabled={save.isPending}>
              {save.isPending ? ar.saving : ar.save}
            </Button>
          </>
        }
      />
      {!creating && product.status !== "active" && <DraftNotice product={product} />}
      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <p className="text-muted-foreground text-xs">{ar.dashboard.requiredLegend}</p>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        {text("name", copy.name, "required")}
        <Field data-invalid={errors.category_id !== undefined}>
          <FieldLabel htmlFor="bundle-category_id">
            <LabelText text={ar.dashboard.productForm.category} need="required" />
          </FieldLabel>
          <Controller
            control={form.control}
            name="category_id"
            render={({ field }) => (
              <CategoryPicker
                id="bundle-category_id"
                ref={field.ref}
                value={field.value}
                onChange={field.onChange}
                onBlur={field.onBlur}
                categories={activeOnly(categories)}
                allCategories={categories}
                invalid={errors.category_id !== undefined}
              />
            )}
          />
          <FieldError errors={[errors.category_id]} />
        </Field>
        {text("code", copy.code, "required", true)}
        <Field data-invalid={errors.unit_id !== undefined}>
          <FieldLabel htmlFor="bundle-unit_id">
            <LabelText text={copy.unit} need="required" />
          </FieldLabel>
          <NativeSelect id="bundle-unit_id" aria-invalid={errors.unit_id !== undefined} {...form.register("unit_id")}>
            <option value="">{ar.dashboard.productForm.chooseUnit}</option>
            {activeUnits.map((unit) => (
              <option key={unit.id} value={unit.id}>
                {unit.name}
              </option>
            ))}
          </NativeSelect>
          <FieldError errors={[errors.unit_id]} />
        </Field>
        {text("moq", copy.moq, "optional", true)}
        {creating && (
          <Field>
            <FieldLabel htmlFor="bundle-status">{copy.status}</FieldLabel>
            <NativeSelect id="bundle-status" {...form.register("status")}>
              {BUNDLE_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {copy.statuses[status]}
                </option>
              ))}
            </NativeSelect>
          </Field>
        )}
        <Field className="md:col-span-2">
          <FieldLabel htmlFor="bundle-description">
            <LabelText text={ar.dashboard.productForm.description} need="optional" />
          </FieldLabel>
          <Textarea id="bundle-description" rows={3} {...form.register("description")} />
        </Field>
      </div>

      <fieldset className="flex min-w-0 flex-col gap-3 rounded-xl border p-4" data-testid="bundle-components">
        <legend className="px-1 font-bold">{copy.components}</legend>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-muted-foreground text-sm">{copy.componentsHint}</p>
          <BundleComponentPicker
            picked={components.map((component) => component.sku_unit_id)}
            disabled={components.length >= MAX_COMPONENTS}
            onPick={(component) => {
              if (addComponent(components, component) !== components) {
                rows.append(component);
                form.clearErrors("components");
              }
            }}
          />
        </div>
        {rows.fields.length === 0 ? (
          <p className="text-muted-foreground text-sm">{copy.empty}</p>
        ) : (
          <ul className="flex flex-col divide-y rounded-md border">
            {rows.fields.map((row, index) => {
              const rowErrors = errors.components?.[index];
              const name = row.label ? `${row.product_name} (${row.label})` : row.product_name;

              return (
                <li
                  key={row.id}
                  className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                  data-testid={`component-${row.sku_unit_id}`}
                >
                  <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="font-medium">{name}</span>
                    <span className="text-muted-foreground text-xs">
                      <bdi dir="ltr">{row.sku_code}</bdi> ·{" "}
                      {copy.perUnit(
                        formatMoney(row.base_price),
                        row.conversion_factor > 1 ? `${row.unit} (${row.conversion_factor})` : row.unit,
                      )}
                    </span>
                    {row.available !== null && (
                      <span className="text-muted-foreground text-xs">{copy.componentStock(row.available)}</span>
                    )}
                    {!row.orderable && (
                      <Badge variant="destructive" className="self-start">
                        {copy.notOrderable}
                      </Badge>
                    )}
                    <FieldError errors={[rowErrors?.sku_unit_id, rowErrors?.quantity]} />
                  </span>
                  <span className="flex items-center gap-1">
                    <Input
                      aria-label={copy.quantityOf(name)}
                      dir="ltr"
                      inputMode="numeric"
                      className="w-20 text-center"
                      aria-invalid={rowErrors?.quantity !== undefined}
                      {...form.register(`components.${index}.quantity`)}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={copy.moveUp(name)}
                      disabled={index === 0}
                      onClick={() => rows.move(index, index - 1)}
                    >
                      <ArrowUpIcon />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={copy.moveDown(name)}
                      disabled={index === rows.fields.length - 1}
                      onClick={() => rows.move(index, index + 1)}
                    >
                      <ArrowDownIcon />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="text-destructive"
                      aria-label={copy.remove(name)}
                      onClick={() => rows.remove(index)}
                    >
                      <Trash2Icon />
                    </Button>
                  </span>
                </li>
              );
            })}
          </ul>
        )}
        {componentsError !== undefined && <FieldError role="alert">{componentsError}</FieldError>}
      </fieldset>

      <fieldset className="grid grid-cols-1 gap-4 rounded-xl border p-4 md:grid-cols-2">
        <legend className="px-1 font-bold">{copy.pricing}</legend>
        <Field>
          <FieldLabel htmlFor="bundle-pricing-mode">{copy.mode}</FieldLabel>
          <NativeSelect id="bundle-pricing-mode" {...form.register("pricing.mode")}>
            <option value="fixed">{copy.modes.fixed}</option>
            <option value="percent_off_sum">{copy.modes.percent_off_sum}</option>
          </NativeSelect>
        </Field>
        <Field data-invalid={errors.pricing?.value !== undefined}>
          <FieldLabel htmlFor="bundle-pricing-value">
            <LabelText text={pricing.mode === "fixed" ? copy.fixedValue : copy.percentValue} need="required" />
          </FieldLabel>
          <Input
            id="bundle-pricing-value"
            dir="ltr"
            className="text-start"
            inputMode="decimal"
            aria-invalid={errors.pricing?.value !== undefined}
            {...form.register("pricing.value")}
          />
          <FieldError errors={[errors.pricing?.value, errors.pricing?.mode]} />
        </Field>
        <dl className="grid grid-cols-2 gap-1 text-sm md:col-span-2 md:max-w-sm" data-testid="bundle-totals">
          <dt className="text-muted-foreground">{copy.sum}</dt>
          <dd data-testid="bundle-sum">{formatMoney(totals.sum)}</dd>
          <dt className="text-muted-foreground">{copy.price}</dt>
          <dd className="font-bold" data-testid="bundle-price">
            {totals.price === null ? "—" : formatMoney(totals.price)}
          </dd>
          <dt className="text-muted-foreground">{copy.saving}</dt>
          <dd className="text-primary font-semibold" data-testid="bundle-saving">
            {totals.saving === null ? "—" : formatMoney(totals.saving)}
          </dd>
        </dl>
        <p className="text-muted-foreground text-xs md:col-span-2">{copy.priceHint}</p>
      </fieldset>

      <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:hidden">
        <Button type="submit" className="h-11 w-full" disabled={save.isPending}>
          {save.isPending ? ar.saving : ar.save}
        </Button>
      </div>
    </form>
  );
}

/** A bundle is active or inactive (no Coming Soon); the status changes on its own (PATCH status). */
function BundleStatusControl({ product }: { product: DashboardProduct }) {
  const setStatus = useSetProductStatus(product.id);

  return (
    <NativeSelect
      aria-label={copy.status}
      value={product.status === "active" ? "active" : "inactive"}
      disabled={setStatus.isPending}
      onChange={(event) =>
        setStatus.mutate(event.target.value as ProductStatus, {
          onSuccess: () => toast.success(copy.statusSaved),
          onError: (failure) => toast.error(messageFor(failure)),
        })
      }
    >
      {BUNDLE_STATUSES.map((status) => (
        <option key={status} value={status}>
          {copy.statuses[status]}
        </option>
      ))}
    </NativeSelect>
  );
}

/** An inactive bundle (a generated grade set starts so) is hidden from the store until the merchant activates it. */
function DraftNotice({ product }: { product: DashboardProduct }) {
  const setStatus = useSetProductStatus(product.id);

  return (
    <Alert data-testid="bundle-draft">
      <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
        <span>{copy.draft}</span>
        <Button
          type="button"
          size="sm"
          disabled={setStatus.isPending}
          onClick={() =>
            setStatus.mutate("active", {
              onSuccess: () => toast.success(copy.statusSaved),
              onError: (failure) => toast.error(messageFor(failure)),
            })
          }
        >
          {copy.activate}
        </Button>
      </AlertDescription>
    </Alert>
  );
}
