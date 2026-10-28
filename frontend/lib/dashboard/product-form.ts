import { z } from "zod";

import type { CategoryKind, DashboardProduct, DashboardSkuUnit, FieldDefinition, ProductStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import { type CustomFieldDrafts, customFieldsBody, draftsFromProduct } from "./custom-fields";
import { type TierDraft, tiersBody, toBasisPoints, toPiasters } from "./price-math";
import type { ProductCreateInput } from "./catalog";

// The product form (08 §6): values as the inputs hold them, the zod schema mirroring ProductPayloadRules,
// and the conversion to the create/update body. The SKU is sold in one or more units (V2 Task 13, D91), each with
// its own factor, prices, MOQ and tiers; exactly one is the default.

const copy = ar.dashboard.productForm;

export const MAX_UNITS = 10;

const integer = (min: number, max: number) =>
  z
    .string()
    .trim()
    .regex(/^\d+$/, copy.invalidInteger)
    .refine((value) => Number(value) >= min && Number(value) <= max, copy.invalidInteger);

const unitSchema = z.object({
  /** The stored unit's id, "" for a new one. */
  id: z.string(),
  unit_id: z.string().min(1, copy.required),
  conversion_factor: integer(1, 1_000_000),
  cover_price: z
    .string()
    .trim()
    .refine((value) => value === "" || toPiasters(value) !== null, copy.invalidMoney),
  base_mode: z.enum(["price", "percent"]),
  base_value: z.string().trim(),
  moq: integer(1, 1_000_000),
  tiers: z.array(z.object({ min_quantity: z.string(), mode: z.enum(["price", "percent"]), value: z.string() })),
});

export type UnitFormValues = z.infer<typeof unitSchema>;

export const productSchema = z
  .object({
    name: z.string().trim().min(1, copy.required).max(200),
    category_id: z.string().min(1, copy.required),
    description: z.string().max(5000),
    status: z.enum(["active", "coming_soon", "inactive"]),
    code: z
      .string()
      .trim()
      .min(1, copy.required)
      .max(60)
      .regex(/^[A-Za-z0-9][A-Za-z0-9._/-]*$/, copy.required),
    base_unit_id: z.string().min(1, copy.required),
    units: z.array(unitSchema).min(1, copy.units.atLeastOne).max(MAX_UNITS, copy.units.atMost(MAX_UNITS)),
    /** The index of the default unit in `units`. */
    default_unit: z.number().int().min(0),
    opening_stock: z
      .string()
      .trim()
      .refine((value) => value === "" || /^\d{1,8}$/.test(value), copy.invalidInteger),
    custom: z.record(z.string(), z.string()),
    // BR-O14, D88: pre-orders while Coming Soon.
    preorder_enabled: z.boolean(),
    expected_at: z
      .string()
      .trim()
      .refine((value) => value === "" || /^\d{4}-\d{2}-\d{2}$/.test(value), copy.invalidDate),
    preorder_limit: z
      .string()
      .trim()
      .refine(
        (value) => value === "" || (/^\d{1,8}$/.test(value) && Number(value) >= 1 && Number(value) <= 10_000_000),
        copy.invalidInteger,
      ),
  })
  .superRefine((values, context) => {
    const seen = new Set<string>();

    values.units.forEach((unit, index) => {
      if (unit.unit_id !== "" && seen.has(unit.unit_id)) {
        context.addIssue({ code: "custom", path: ["units", index, "unit_id"], message: copy.units.duplicate });
      }

      seen.add(unit.unit_id);

      if (unit.base_mode === "percent") {
        if (unit.cover_price === "") {
          // The schema is shared by every section, so the message names the reference price both ways.
          context.addIssue({
            code: "custom",
            path: ["units", index, "base_value"],
            message: copy.percentNeedsCover(ar.product.referencePrice(null)),
          });
        } else if (toBasisPoints(unit.base_value) === null) {
          context.addIssue({ code: "custom", path: ["units", index, "base_value"], message: copy.invalidPercent });
        }
      } else if (toPiasters(unit.base_value) === null || toPiasters(unit.base_value) === 0n) {
        context.addIssue({ code: "custom", path: ["units", index, "base_value"], message: copy.invalidMoney });
      }
    });
  });

export type ProductFormValues = z.infer<typeof productSchema>;

function tierDrafts(unit: DashboardSkuUnit): TierDraft[] {
  return (unit.tiers ?? []).map((tier) =>
    tier.discount_percent === null
      ? { min_quantity: String(tier.min_quantity), mode: "price", value: tier.unit_price }
      : { min_quantity: String(tier.min_quantity), mode: "percent", value: tier.discount_percent },
  );
}

/** A unit row as the form holds it: a stored unit, or a blank new one (factor 1, MOQ 1). */
export function unitDraft(unit: DashboardSkuUnit | null = null): UnitFormValues {
  const percent = unit?.base_price_percent ?? null;

  return {
    id: unit === null ? "" : String(unit.id),
    unit_id: unit?.unit ? String(unit.unit.id) : "",
    conversion_factor: String(unit?.conversion_factor ?? 1),
    cover_price: unit?.cover_price ?? "",
    base_mode: percent === null ? "price" : "percent",
    base_value: percent ?? unit?.base_price ?? "",
    moq: String(unit?.moq ?? 1),
    tiers: unit === null ? [] : tierDrafts(unit),
  };
}

/** The form's starting values: the product's, or a blank new product (active, one unit). */
export function productDefaults(product: DashboardProduct | null): ProductFormValues {
  const sku = product?.sku ?? null;
  const units = sku?.units ?? [];
  const defaultIndex = units.findIndex((unit) => unit.is_default);

  return {
    name: product?.name ?? "",
    category_id: product?.category ? String(product.category.id) : "",
    description: product?.description ?? "",
    status: product?.status ?? "active",
    code: sku?.code ?? "",
    base_unit_id: sku?.base_unit ? String(sku.base_unit.id) : "",
    units: units.length === 0 ? [unitDraft()] : units.map((unit) => unitDraft(unit)),
    default_unit: Math.max(0, defaultIndex),
    opening_stock: "",
    custom: product === null ? {} : draftsFromProduct(product.custom_fields),
    preorder_enabled: product?.preorder.enabled ?? false,
    expected_at: product?.preorder.expected_at ?? "",
    preorder_limit: product?.preorder.limit === null || product === null ? "" : String(product.preorder.limit),
  };
}

/**
 * The API body. Prices go as the merchant entered them, per unit: `base_price` or `base_price_percent`, and per tier
 * `unit_price` or `discount_percent` (BR-PR10). Custom fields are those of the chosen category's section (D69).
 * `status` and `opening_stock` only on create. The pre-order settings always go (D88: an empty day or cap is none).
 */
export function productBody(
  values: ProductFormValues,
  definitions: readonly FieldDefinition[],
  creating: boolean,
  kind: CategoryKind | null,
): ProductCreateInput {
  const body: ProductCreateInput = {
    name: values.name.trim(),
    category_id: Number(values.category_id),
    description: values.description.trim() === "" ? null : values.description.trim(),
    custom_fields: customFieldsBody(definitions, values.custom as CustomFieldDrafts, kind),
    sku: {
      code: values.code.trim(),
      base_unit_id: Number(values.base_unit_id),
      units: values.units.map((unit, index) => {
        const percent = unit.base_mode === "percent";

        return {
          ...(unit.id === "" ? {} : { id: Number(unit.id) }),
          unit_id: Number(unit.unit_id),
          conversion_factor: Number(unit.conversion_factor),
          cover_price: unit.cover_price === "" ? null : unit.cover_price,
          base_price: percent ? null : unit.base_value,
          base_price_percent: percent ? unit.base_value : null,
          moq: Number(unit.moq),
          tiers: tiersBody(unit.tiers),
          is_default: index === values.default_unit,
        };
      }),
    },
    preorder: {
      enabled: values.preorder_enabled,
      expected_at: values.expected_at === "" ? null : values.expected_at,
      limit: values.preorder_limit === "" ? null : Number(values.preorder_limit),
    },
  };

  if (creating) {
    body.status = values.status as ProductStatus;

    if (values.opening_stock !== "") {
      body.opening_stock = Number(values.opening_stock);
    }
  }

  return body;
}

type FormPath =
  | "name"
  | "category_id"
  | "description"
  | "code"
  | "base_unit_id"
  | "units"
  | "opening_stock"
  | "preorder_enabled"
  | "expected_at"
  | "preorder_limit"
  | `units.${number}.${"unit_id" | "conversion_factor" | "cover_price" | "base_value" | "moq"}`;

/** Form field → the backend's `details` key, for mapping validation errors; the units' keys for `unitCount` units. */
export function serverFields(unitCount: number): (readonly [FormPath, string])[] {
  const fields: (readonly [FormPath, string])[] = [
    ["name", "name"],
    ["category_id", "category_id"],
    ["description", "description"],
    ["code", "sku.code"],
    ["base_unit_id", "sku.base_unit_id"],
    ["units", "sku.units"],
    ["opening_stock", "opening_stock"],
    ["preorder_enabled", "preorder.enabled"],
    ["expected_at", "preorder.expected_at"],
    ["preorder_limit", "preorder.limit"],
  ];

  for (let index = 0; index < unitCount; index++) {
    const key = `sku.units.${index}`;
    fields.push(
      [`units.${index}.unit_id`, `${key}.unit_id`],
      [`units.${index}.unit_id`, `${key}.id`],
      [`units.${index}.conversion_factor`, `${key}.conversion_factor`],
      [`units.${index}.cover_price`, `${key}.cover_price`],
      [`units.${index}.base_value`, `${key}.base_price`],
      [`units.${index}.base_value`, `${key}.base_price_percent`],
      [`units.${index}.moq`, `${key}.moq`],
    );
  }

  return fields;
}

/** `invalid_tiers` details (`sku.units.{i}.tiers`) → the problems of each unit, by unit index. */
export function serverTierProblems(
  details: Record<string, unknown> | null | undefined,
  fallback: string,
): Record<number, string[]> {
  const problems: Record<number, string[]> = {};

  for (const [key, value] of Object.entries(details ?? {})) {
    const match = /^sku\.units\.(\d+)\.tiers$/.exec(key);
    const index = match === null ? 0 : Number(match[1]);
    const messages = Array.isArray(value) ? value.map(String) : [String(value)];
    problems[index] = [...(problems[index] ?? []), ...messages];
  }

  return Object.keys(problems).length === 0 ? { 0: [fallback] } : problems;
}
