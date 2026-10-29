"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { z } from "zod";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type {
  BundleRequest,
  DashboardProduct,
  DashboardSku,
  DashboardSkuUnit,
  FieldDefinition,
  ProductFilter,
} from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import { divideHalfUp, fromPiasters, toPiasters } from "./price-math";

// BR-P15, D93: a bundle is one product sold in one unit, made of component SKU units with a quantity each, priced at a
// fixed price or at the components' sum less a %. The editor shows the sum, price and saving live; the server computes
// the real ones (and applies groups and promotions to the bundle price).

const copy = ar.dashboard.bundles;
const required = ar.dashboard.productForm.required;

/** The backend's limits (BundlePayloadRules). */
export const MAX_COMPONENTS = 50;
const MAX_QUANTITY = 1000;
const CODE = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/;
const MONEY = /^\d{1,7}(\.\d{1,2})?$/;
const PERCENT = /^\d{1,2}(\.\d{1,2})?$/;

export type BundlePriceMode = "fixed" | "percent_off_sum";

// ---- Price maths (display only) ----

/** "12.5" → 1250n basis points, 0 allowed (a generated set starts at 0 %); null unless 0 ≤ percent < 100. */
export function percentPoints(value: string): bigint | null {
  const trimmed = value.trim();

  if (!PERCENT.test(trimmed)) {
    return null;
  }

  const [whole = "0", fraction = ""] = trimmed.split(".");

  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));
}

/** A fixed bundle price: at least 0.01, two decimals at most; null otherwise. */
export function fixedPrice(value: string): bigint | null {
  const trimmed = value.trim();

  if (!MONEY.test(trimmed)) {
    return null;
  }

  const piasters = toPiasters(trimmed);

  return piasters !== null && piasters >= 1n ? piasters : null;
}

/** A component quantity typed in the form: a whole number 1..1000, else null. */
export function componentQuantity(value: string | number): number | null {
  const text = String(value).trim();

  if (!/^\d+$/.test(text)) {
    return null;
  }

  const quantity = Number(text);

  return quantity >= 1 && quantity <= MAX_QUANTITY ? quantity : null;
}

export type BundleTotals = {
  /** Σ unit base price × quantity of the picked units (an invalid quantity counts as 0). */
  sum: string;
  /** The fixed price, or the sum less the % half-up; null while the value is not valid. */
  price: string | null;
  /** sum − price, never below 0; null with the price. */
  saving: string | null;
};

/** The live sum / price / saving of the editor (D93): the same rounding as the server's BundlePricer. */
export function bundleTotals(
  components: readonly { base_price: string; quantity: string | number }[],
  pricing: { mode: BundlePriceMode; value: string },
): BundleTotals {
  let sum = 0n;

  for (const component of components) {
    sum += (toPiasters(component.base_price) ?? 0n) * BigInt(componentQuantity(component.quantity) ?? 0);
  }

  let price: bigint | null;

  if (pricing.mode === "fixed") {
    price = fixedPrice(pricing.value);
  } else {
    const points = percentPoints(pricing.value);
    price = points === null || points >= 10000n ? null : divideHalfUp(sum * (10000n - points), 10000n);
  }

  return {
    sum: fromPiasters(sum),
    price: price === null ? null : fromPiasters(price),
    saving: price === null ? null : fromPiasters(sum > price ? sum - price : 0n),
  };
}

// ---- Components ----

/** One component row of the editor: what is sent (unit id, quantity) and what is shown next to it. */
export type ComponentDraft = {
  sku_unit_id: number;
  quantity: string;
  product_name: string;
  sku_code: string;
  label: string | null;
  unit: string;
  conversion_factor: number;
  base_price: string;
  /** Base units in stock; null when not known (a unit picked from the list before saving). */
  available: number | null;
  orderable: boolean;
};

/** A unit a component can be picked in: an active SKU of an active simple product, in one of its units. */
export type PickableUnit = { sku: DashboardSku; unit: DashboardSkuUnit };

/** BR-P15: what of a product can go into a bundle: every unit of its active SKUs; nothing of a bundle (one level). */
export function pickableUnits(product: DashboardProduct): PickableUnit[] {
  if (product.type === "bundle" || product.status !== "active") {
    return [];
  }

  const skus = product.skus.length > 0 ? product.skus : product.sku === null ? [] : [product.sku];

  return skus
    .filter((sku) => sku.status !== "inactive")
    .flatMap((sku) => (sku.units ?? []).map((unit) => ({ sku, unit })));
}

export function componentFrom(product: DashboardProduct, { sku, unit }: PickableUnit): ComponentDraft {
  return {
    sku_unit_id: unit.id,
    quantity: "1",
    product_name: product.name,
    sku_code: sku.code,
    label: sku.label ?? null,
    unit: unit.unit?.name ?? "",
    conversion_factor: unit.conversion_factor,
    base_price: unit.base_price,
    available: sku.inventory?.available ?? null,
    orderable: true,
  };
}

/** Adds a component unit once (the API takes each unit once per bundle); at most 50. */
export function addComponent(components: ComponentDraft[], component: ComponentDraft): ComponentDraft[] {
  if (components.length >= MAX_COMPONENTS || components.some((row) => row.sku_unit_id === component.sku_unit_id)) {
    return components;
  }

  return [...components, component];
}

/** The rows with one moved up (−1) or down (+1); unchanged at either end. */
export function moveComponent<T>(rows: readonly T[], index: number, step: -1 | 1): T[] {
  const target = index + step;

  if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) {
    return [...rows];
  }

  const next = [...rows];
  const [moved] = next.splice(index, 1);

  if (moved !== undefined) {
    next.splice(target, 0, moved);
  }

  return next;
}

// ---- The form ----

const componentSchema = z.object({
  sku_unit_id: z.number(),
  quantity: z
    .string()
    .trim()
    .refine((value) => componentQuantity(value) !== null, copy.invalidQuantity),
  product_name: z.string(),
  sku_code: z.string(),
  label: z.string().nullable(),
  unit: z.string(),
  conversion_factor: z.number(),
  base_price: z.string(),
  available: z.number().nullable(),
  orderable: z.boolean(),
});

/** Mirrors BundlePayloadRules: name, category, code, unit, MOQ, 1..50 components, the pricing. */
export const bundleSchema = z
  .object({
    name: z.string().trim().min(1, required).max(200),
    category_id: z.string().min(1, required),
    description: z.string().max(5000),
    code: z
      .string()
      .trim()
      .min(1, required)
      .max(60, ar.dashboard.productForm.variants.invalidCode)
      .regex(CODE, ar.dashboard.productForm.variants.invalidCode),
    unit_id: z.string().min(1, required),
    moq: z
      .string()
      .trim()
      .refine(
        (value) => value === "" || (/^\d{1,7}$/.test(value) && Number(value) >= 1),
        ar.dashboard.productForm.invalidInteger,
      ),
    status: z.enum(["active", "inactive"]),
    components: z.array(componentSchema).min(1, copy.atLeastOne).max(MAX_COMPONENTS, copy.atMost(MAX_COMPONENTS)),
    pricing: z.object({ mode: z.enum(["fixed", "percent_off_sum"]), value: z.string().trim() }),
  })
  .superRefine((values, context) => {
    const valid =
      values.pricing.mode === "fixed"
        ? fixedPrice(values.pricing.value) !== null
        : (percentPoints(values.pricing.value) ?? 10000n) < 10000n;

    if (!valid) {
      context.addIssue({
        code: "custom",
        path: ["pricing", "value"],
        message: values.pricing.mode === "fixed" ? copy.invalidPrice : copy.invalidPercent,
      });
    }
  });

export type BundleFormValues = z.infer<typeof bundleSchema>;

/** The form's starting values: empty for a new bundle, the stored bundle on edit. */
export function bundleDefaults(product: DashboardProduct | null): BundleFormValues {
  const sku = product?.sku ?? null;
  const unit = sku?.units?.[0];
  const pricing = product?.bundle?.pricing ?? null;

  return {
    name: product?.name ?? "",
    category_id: product?.category ? String(product.category.id) : "",
    description: product?.description ?? "",
    code: sku?.code ?? "",
    unit_id: String(unit?.unit?.id ?? sku?.selling_unit?.id ?? ""),
    moq: String(unit?.moq ?? sku?.moq ?? 1),
    status: product === null || product.status === "active" ? "active" : "inactive",
    components: (product?.bundle?.components ?? []).map((component) => ({
      sku_unit_id: component.sku_unit_id,
      quantity: String(component.quantity),
      product_name: component.product_name,
      sku_code: component.sku_code,
      label: component.label,
      unit: component.unit,
      conversion_factor: component.conversion_factor,
      base_price: component.base_price,
      available: component.available,
      orderable: component.orderable,
    })),
    pricing: { mode: pricing?.mode ?? "percent_off_sum", value: pricing?.value ?? "0" },
  };
}

/**
 * The request body. On edit the stored custom fields go back unchanged (the bundle editor has no field inputs; a
 * generated set keeps the values its books share). `status` is only sent on create (PATCH status afterwards).
 */
export function bundleBody(
  values: BundleFormValues,
  product: DashboardProduct | null,
): BundleRequest & { status?: "active" | "inactive" } {
  const body: BundleRequest & { status?: "active" | "inactive" } = {
    name: values.name.trim(),
    category_id: Number(values.category_id),
    description: values.description.trim() === "" ? null : values.description.trim(),
    code: values.code.trim(),
    unit_id: Number(values.unit_id),
    moq: values.moq.trim() === "" ? 1 : Number(values.moq),
    components: values.components.map((component) => ({
      sku_unit_id: component.sku_unit_id,
      quantity: Number(component.quantity),
    })),
    pricing: { mode: values.pricing.mode, value: values.pricing.value.trim() },
  };

  if (product === null) {
    body.status = values.status;
  } else if (Object.keys(product.custom_fields).length > 0) {
    body.custom_fields = { ...product.custom_fields };
  }

  return body;
}

/** The fields a `validation_failed` may name, as form paths (the API keys are the same paths). */
export function bundleServerFields(components: number): string[] {
  const fields: string[] = [
    "name",
    "category_id",
    "description",
    "code",
    "unit_id",
    "moq",
    "components",
    "pricing.mode",
    "pricing.value",
  ];

  for (let index = 0; index < components; index++) {
    fields.push(`components.${index}.sku_unit_id`, `components.${index}.quantity`);
  }

  return fields;
}

// ---- The grade set wizard ----

/**
 * The generate filter (GenerateGradeSet) from the wizard's selection: the category and the chosen facet values; null
 * when nothing is chosen (the API refuses an empty filter).
 */
export function gradeSetFilter(category: string, facets: Record<string, string>): ProductFilter | null {
  const filter: ProductFilter = {};
  const chosen = Object.fromEntries(Object.entries(facets).filter(([, value]) => value !== ""));

  if (category !== "") {
    filter.category = Number(category);
  }

  if (Object.keys(chosen).length > 0) {
    filter.f = chosen;
  }

  return Object.keys(filter).length > 0 ? filter : null;
}

/** The wizard's starting selection from the products list URL (its category and `f[key]` facets). */
export function gradeSetSelection(
  query: Record<string, string>,
  facets: readonly FieldDefinition[],
): { category: string; facets: Record<string, string> } {
  return {
    category: query.category ?? "",
    facets: Object.fromEntries(facets.map((facet) => [facet.key, query[`f[${facet.key}]`] ?? ""])),
  };
}

// ---- Hooks ----

function useBundleWrite<TInput>(request: (input: TInput) => Promise<DashboardProduct>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (product) => {
      queryClient.setQueryData(queryKeys.dashboardProduct(product.id), product);
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}

/** Creates a bundle (POST /bundles) or replaces one (PUT /bundles/{id}); the response is the full product. */
export function useSaveBundle() {
  return useBundleWrite(({ id, body }: { id?: number; body: BundleRequest & { status?: "active" | "inactive" } }) =>
    id === undefined
      ? apiFetch<DashboardProduct>("/api/dashboard/bundles", { method: "POST", body })
      : apiFetch<DashboardProduct>(`/api/dashboard/bundles/${id}`, { method: "PUT", body }),
  );
}

/** GenerateGradeSet: a draft (inactive) bundle of every active product the filter matches. */
export function useGenerateBundle() {
  return useBundleWrite((filter: ProductFilter) =>
    apiFetch<DashboardProduct>("/api/dashboard/bundles/generate", { method: "POST", body: { filter } }),
  );
}
