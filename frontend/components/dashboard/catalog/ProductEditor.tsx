"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import type { DashboardCategory, DashboardProduct, FieldDefinition, ProductStatus, Unit } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  useDashboardCategories,
  useDashboardProduct,
  useFields,
  useSaveProduct,
  useSetProductStatus,
  useUnits,
} from "@/lib/dashboard/catalog";
import { activeOnly, kindOfCategory } from "@/lib/dashboard/category-tree";
import { customFieldProblems, visibleDefinitions } from "@/lib/dashboard/custom-fields";
import { priceFromPercent, tierProblems } from "@/lib/dashboard/price-math";
import {
  productBody,
  productDefaults,
  type ProductFormValues,
  productSchema,
  SERVER_FIELDS,
} from "@/lib/dashboard/product-form";
import { useRecomputePricing } from "@/lib/dashboard/pricing";

import { CategoryPicker } from "../common/CategoryPicker";
import { LabelText } from "../common/LabelText";
import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { CustomFieldInputs } from "./CustomFieldInputs";
import { ProductImages } from "./ProductImages";
import { ProductRelations } from "./ProductRelations";
import { PriceInput } from "./PriceInput";
import { TiersEditor } from "./TiersEditor";

const copy = ar.dashboard.productForm;
const STATUSES: ProductStatus[] = ["active", "coming_soon", "inactive"];
type Tab = "basics" | "fields" | "pricing" | "images" | "relations";

/** Loads what the form needs (the product on edit, fields, units, categories) and renders it. */
export function ProductEditor({ productId }: { productId?: number }) {
  const product = useDashboardProduct(productId);
  const fields = useFields();
  const units = useUnits();
  const categories = useDashboardCategories();
  const failed = [product, fields, units, categories].find((query) => query.isError);

  if (failed !== undefined) {
    return <ErrorBlock error={failed.error} onRetry={() => void failed.refetch()} />;
  }

  if (
    fields.data === undefined ||
    units.data === undefined ||
    categories.data === undefined ||
    (productId !== undefined && product.data === undefined)
  ) {
    return <LoadingBlock rows={8} />;
  }

  return (
    <ProductForm
      // Remount on every saved change (save, recompute) so the form never holds prices older than the server's.
      key={product.data === undefined ? "new" : `${product.data.id}-${product.data.updated_at}`}
      product={product.data ?? null}
      definitions={fields.data}
      units={units.data}
      categories={categories.data}
    />
  );
}

type FormProps = {
  product: DashboardProduct | null;
  definitions: FieldDefinition[];
  units: Unit[];
  categories: DashboardCategory[];
};

export function ProductForm({ product, definitions, units, categories }: FormProps) {
  const router = useRouter();
  const save = useSaveProduct();
  const creating = product === null;
  const [tab, setTab] = useState<Tab>("basics");
  const [tierErrors, setTierErrors] = useState<Record<number, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [serverTierProblems, setServerTierProblems] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productSchema),
    defaultValues: productDefaults(product),
  });
  const errors = form.formState.errors;
  const [coverPrice, baseMode, baseValue, categoryId] = useWatch({
    control: form.control,
    name: ["cover_price", "base_mode", "base_value", "category_id"],
  });
  // The chosen category's section (D68): names the reference price and picks the custom fields (D69).
  const kind = kindOfCategory(categories, categoryId === "" ? null : Number(categoryId));
  const referenceLabel = ar.product.referencePrice(kind);
  const tierMessages = { ...copy.tierErrors, percentNeedsCover: copy.tierErrors.percentNeedsCover(referenceLabel) };
  const cover = coverPrice.trim() === "" ? null : coverPrice.trim();
  const basePrice =
    baseMode === "percent" ? (cover === null ? null : priceFromPercent(cover, baseValue)) : baseValue.trim() || null;
  const activeUnits = units.filter(
    (unit) =>
      unit.status === "active" || unit.id === product?.sku?.selling_unit?.id || unit.id === product?.sku?.base_unit?.id,
  );
  const activeCategories = activeOnly(categories);

  const onInvalid = () => setTab("basics");

  const submit = form.handleSubmit((valid) => {
    setError(null);
    setServerTierProblems([]);
    const tiers = tierProblems(valid.tiers, { cover, basePrice }, tierMessages);
    const custom = customFieldProblems(definitions, valid.custom, kind, {
      required: copy.required,
      number: copy.invalidInteger,
    });
    setTierErrors(tiers);
    setFieldErrors(custom);

    if (Object.keys(custom).length > 0) {
      setTab("fields");
      return;
    }

    if (Object.keys(tiers).length > 0) {
      setTab("pricing");
      return;
    }

    save.mutate(
      { id: product?.id, body: productBody(valid, definitions, creating, kind) },
      {
        onSuccess: (saved) => {
          toast.success(creating ? copy.created : copy.saved);
          if (creating) {
            router.replace(`/dashboard/products/${saved.id}`);
          }
        },
        onError: (failure) => showServerErrors(failure),
      },
    );
  }, onInvalid);

  const showServerErrors = (failure: unknown) => {
    if (isApiError(failure, "invalid_tiers")) {
      const problems = failure.details?.tiers;
      setServerTierProblems(Array.isArray(problems) ? problems.map(String) : [failure.message]);
      setTab("pricing");
      return;
    }

    if (isApiError(failure, "validation_failed")) {
      let mapped = false;

      for (const [field, key] of SERVER_FIELDS) {
        const message = failure.fieldMessage(key);

        if (message !== null) {
          form.setError(field, { type: "server", message });
          mapped = true;
        }
      }

      const custom: Record<string, string> = {};
      const hidden: string[] = [];

      for (const definition of definitions) {
        const message = failure.fieldMessage(`custom_fields.${definition.key}`);

        if (message === null) {
          continue;
        }

        // A field of another section has no input on this form (D69): its message goes to the page alert.
        if (visibleDefinitions(definitions, form.getValues("custom"), kind).includes(definition)) {
          custom[definition.key] = message;
        } else {
          hidden.push(`${definition.label}: ${message}`);
        }
      }

      setFieldErrors(custom);

      if (Object.keys(custom).length > 0) {
        setTab("fields");
      } else if (mapped) {
        setTab("basics");
      }

      if (hidden.length > 0) {
        setError(hidden.join("، "));
        return;
      }

      if (mapped || Object.keys(custom).length > 0) {
        return;
      }
    }

    setError(messageFor(failure));
  };

  const text = (
    name: "name" | "code" | "conversion_factor" | "moq" | "opening_stock" | "cover_price",
    label: string,
    need: "required" | "optional",
    ltr = false,
  ) => (
    <Field data-invalid={errors[name] !== undefined}>
      <FieldLabel htmlFor={`product-${name}`}>
        <LabelText text={label} need={need} />
      </FieldLabel>
      <Input
        id={`product-${name}`}
        dir={ltr ? "ltr" : undefined}
        className={ltr ? "text-start" : undefined}
        aria-invalid={errors[name] !== undefined}
        {...form.register(name)}
      />
      <FieldError errors={[errors[name]]} />
    </Field>
  );

  const unitSelect = (name: "selling_unit_id" | "base_unit_id", label: string) => (
    <Field data-invalid={errors[name] !== undefined}>
      <FieldLabel htmlFor={`product-${name}`}>
        <LabelText text={label} need="required" />
      </FieldLabel>
      <NativeSelect id={`product-${name}`} aria-invalid={errors[name] !== undefined} {...form.register(name)}>
        <option value="">{copy.chooseUnit}</option>
        {activeUnits.map((unit) => (
          <option key={unit.id} value={unit.id}>
            {unit.name}
          </option>
        ))}
      </NativeSelect>
      <FieldError errors={[errors[name]]} />
    </Field>
  );

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 pb-20 md:pb-0">
      <PageHeader
        title={creating ? copy.newTitle : product.name}
        actions={
          <>
            {!creating && <StatusControl product={product} />}
            <Button type="submit" className="hidden md:inline-flex" disabled={save.isPending}>
              {save.isPending ? ar.saving : ar.save}
            </Button>
          </>
        }
      />
      {error !== null && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <p className="text-muted-foreground text-xs">{ar.dashboard.requiredLegend}</p>
      <Tabs dir="rtl" value={tab} onValueChange={(value) => setTab(value as Tab)}>
        <TabsList className="w-full justify-start overflow-x-auto">
          <TabsTrigger value="basics">{copy.tabs.basics}</TabsTrigger>
          <TabsTrigger value="fields">{copy.tabs.fields}</TabsTrigger>
          <TabsTrigger value="pricing">{copy.tabs.pricing}</TabsTrigger>
          <TabsTrigger value="images">{copy.tabs.images}</TabsTrigger>
          <TabsTrigger value="relations">{copy.tabs.relations}</TabsTrigger>
        </TabsList>

        <TabsContent value="basics" forceMount className="data-[state=inactive]:hidden">
          <div className="grid gap-4 pt-4 md:grid-cols-2">
            {text("name", copy.name, "required")}
            <Field data-invalid={errors.category_id !== undefined}>
              <FieldLabel htmlFor="product-category_id">
                <LabelText text={copy.category} need="required" />
              </FieldLabel>
              <Controller
                control={form.control}
                name="category_id"
                render={({ field }) => (
                  <CategoryPicker
                    id="product-category_id"
                    ref={field.ref}
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    categories={activeCategories}
                    allCategories={categories}
                    invalid={errors.category_id !== undefined}
                  />
                )}
              />
              <FieldError errors={[errors.category_id]} />
            </Field>
            <Field className="md:col-span-2">
              <FieldLabel htmlFor="product-description">
                <LabelText text={copy.description} need="optional" />
              </FieldLabel>
              <Textarea id="product-description" rows={3} {...form.register("description")} />
            </Field>
            {creating && (
              <Field>
                <FieldLabel htmlFor="product-status">{copy.status}</FieldLabel>
                <NativeSelect id="product-status" {...form.register("status")}>
                  {STATUSES.map((status) => (
                    <option key={status} value={status}>
                      {ar.dashboard.productStatuses[status]}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            )}
            {text("code", copy.code, "required", true)}
            {unitSelect("selling_unit_id", copy.sellingUnit)}
            {unitSelect("base_unit_id", copy.baseUnit)}
            {text("conversion_factor", copy.conversion, "required", true)}
            {creating && text("opening_stock", copy.openingStock, "optional", true)}
          </div>
        </TabsContent>

        <TabsContent value="fields" forceMount className="data-[state=inactive]:hidden">
          <div className="pt-4">
            <Controller
              control={form.control}
              name="custom"
              render={({ field }) => (
                <CustomFieldInputs
                  definitions={definitions}
                  values={field.value}
                  kind={kind}
                  errors={fieldErrors}
                  onChange={(key, value) => field.onChange({ ...field.value, [key]: value })}
                />
              )}
            />
          </div>
        </TabsContent>

        <TabsContent value="pricing" forceMount className="data-[state=inactive]:hidden">
          <div className="grid gap-4 pt-4 md:grid-cols-2">
            {text("cover_price", referenceLabel, "optional", true)}
            <Controller
              control={form.control}
              name="base_value"
              render={({ field }) => (
                <PriceInput
                  id="product-base_value"
                  label={<LabelText text={copy.basePrice} need="required" />}
                  mode={baseMode}
                  onModeChange={(mode) => form.setValue("base_mode", mode)}
                  value={field.value}
                  onChange={field.onChange}
                  cover={cover}
                  referenceLabel={referenceLabel}
                  error={errors.base_value?.message}
                />
              )}
            />
            {text("moq", copy.moq, "required", true)}
            <div className="md:col-span-2">
              {serverTierProblems.length > 0 && (
                <Alert variant="destructive" className="mb-3">
                  <AlertDescription>
                    <ul>
                      {serverTierProblems.map((problem) => (
                        <li key={problem}>{problem}</li>
                      ))}
                    </ul>
                  </AlertDescription>
                </Alert>
              )}
              <Controller
                control={form.control}
                name="tiers"
                render={({ field }) => (
                  <TiersEditor
                    tiers={field.value}
                    onChange={field.onChange}
                    cover={cover}
                    referenceLabel={referenceLabel}
                    problems={tierErrors}
                  />
                )}
              />
            </div>
            {!creating && <RecomputeButton productId={product.id} />}
          </div>
        </TabsContent>

        <TabsContent value="images" forceMount className="data-[state=inactive]:hidden">
          <div className="pt-4">{creating ? <AfterSave /> : <ProductImages product={product} />}</div>
        </TabsContent>

        <TabsContent value="relations" forceMount className="data-[state=inactive]:hidden">
          <div className="pt-4">{creating ? <AfterSave /> : <ProductRelations product={product} />}</div>
        </TabsContent>
      </Tabs>

      <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:hidden">
        <Button type="submit" className="h-11 w-full" disabled={save.isPending}>
          {save.isPending ? ar.saving : ar.save}
        </Button>
      </div>
    </form>
  );
}

function AfterSave() {
  return <p className="text-muted-foreground text-sm">{copy.imagesAfterSave}</p>;
}

/** The status of an existing product changes on its own (PATCH status), not with the form. */
function StatusControl({ product }: { product: DashboardProduct }) {
  const setStatus = useSetProductStatus(product.id);

  return (
    <NativeSelect
      aria-label={copy.status}
      value={product.status}
      disabled={setStatus.isPending}
      onChange={(event) =>
        setStatus.mutate(event.target.value as ProductStatus, {
          onSuccess: () => toast.success(copy.statusSaved),
          onError: (failure) => toast.error(messageFor(failure)),
        })
      }
    >
      {STATUSES.map((status) => (
        <option key={status} value={status}>
          {ar.dashboard.productStatuses[status]}
        </option>
      ))}
    </NativeSelect>
  );
}

function RecomputeButton({ productId }: { productId: number }) {
  const recompute = useRecomputePricing(productId);

  return (
    <Button
      type="button"
      variant="outline"
      className="justify-self-start"
      disabled={recompute.isPending}
      onClick={() =>
        recompute.mutate(undefined, {
          onSuccess: () => toast.success(copy.recomputed),
          onError: (failure) => toast.error(messageFor(failure)),
        })
      }
    >
      {copy.recompute}
    </Button>
  );
}
