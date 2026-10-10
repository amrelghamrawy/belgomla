import { z } from "zod";

import type { DashboardProduct, FieldDefinition, ProductStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import { type CustomFieldDrafts, customFieldsBody, draftsFromProduct } from "./custom-fields";
import { type TierDraft, tiersBody, toBasisPoints, toPiasters } from "./price-math";
import type { ProductCreateInput } from "./catalog";

// The product form (08 §6): values as the inputs hold them, the zod schema mirroring ProductPayloadRules,
// and the conversion to the create/update body.

const copy = ar.dashboard.productForm;

const integer = (min: number, max: number) =>
  z
    .string()
    .trim()
    .regex(/^\d+$/, copy.invalidInteger)
    .refine((value) => Number(value) >= min && Number(value) <= max, copy.invalidInteger);

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
    selling_unit_id: z.string().min(1, copy.required),
    base_unit_id: z.string().min(1, copy.required),
    conversion_factor: integer(1, 1_000_000),
    cover_price: z
      .string()
      .trim()
      .refine((value) => value === "" || toPiasters(value) !== null, copy.invalidMoney),
    base_mode: z.enum(["price", "percent"]),
    base_value: z.string().trim(),
    moq: integer(1, 1_000_000),
    opening_stock: z
      .string()
      .trim()
      .refine((value) => value === "" || /^\d{1,8}$/.test(value), copy.invalidInteger),
    tiers: z.array(z.object({ min_quantity: z.string(), mode: z.enum(["price", "percent"]), value: z.string() })),
    custom: z.record(z.string(), z.string()),
  })
  .superRefine((values, context) => {
    if (values.base_mode === "percent") {
      if (values.cover_price === "") {
        context.addIssue({ code: "custom", path: ["base_value"], message: copy.percentNeedsCover });
      } else if (toBasisPoints(values.base_value) === null) {
        context.addIssue({ code: "custom", path: ["base_value"], message: copy.invalidPercent });
      }
    } else if (toPiasters(values.base_value) === null || toPiasters(values.base_value) === 0n) {
      context.addIssue({ code: "custom", path: ["base_value"], message: copy.invalidMoney });
    }
  });

export type ProductFormValues = z.infer<typeof productSchema>;

function tierDrafts(product: DashboardProduct | null): TierDraft[] {
  return (product?.sku?.tiers ?? []).map((tier) =>
    tier.discount_percent === null
      ? { min_quantity: String(tier.min_quantity), mode: "price", value: tier.unit_price }
      : { min_quantity: String(tier.min_quantity), mode: "percent", value: tier.discount_percent },
  );
}

/** The form's starting values: the product's, or a blank new product (active, MOQ 1, factor 1). */
export function productDefaults(product: DashboardProduct | null): ProductFormValues {
  const sku = product?.sku ?? null;
  const percent = sku?.base_price_percent ?? null;

  return {
    name: product?.name ?? "",
    category_id: product?.category ? String(product.category.id) : "",
    description: product?.description ?? "",
    status: product?.status ?? "active",
    code: sku?.code ?? "",
    selling_unit_id: sku?.selling_unit ? String(sku.selling_unit.id) : "",
    base_unit_id: sku?.base_unit ? String(sku.base_unit.id) : "",
    conversion_factor: String(sku?.conversion_factor ?? 1),
    cover_price: sku?.cover_price ?? "",
    base_mode: percent === null ? "price" : "percent",
    base_value: percent ?? sku?.base_price ?? "",
    moq: String(sku?.moq ?? 1),
    opening_stock: "",
    tiers: tierDrafts(product),
    custom: product === null ? {} : draftsFromProduct(product.custom_fields),
  };
}

/**
 * The API body. Prices go as the merchant entered them: `base_price` or `base_price_percent`, and per tier
 * `unit_price` or `discount_percent` (BR-PR10). `status` and `opening_stock` only on create.
 */
export function productBody(
  values: ProductFormValues,
  definitions: readonly FieldDefinition[],
  creating: boolean,
): ProductCreateInput {
  const percent = values.base_mode === "percent";
  const body: ProductCreateInput = {
    name: values.name.trim(),
    category_id: Number(values.category_id),
    description: values.description.trim() === "" ? null : values.description.trim(),
    custom_fields: customFieldsBody(definitions, values.custom as CustomFieldDrafts),
    sku: {
      code: values.code.trim(),
      selling_unit_id: Number(values.selling_unit_id),
      base_unit_id: Number(values.base_unit_id),
      conversion_factor: Number(values.conversion_factor),
      cover_price: values.cover_price === "" ? null : values.cover_price,
      base_price: percent ? null : values.base_value,
      base_price_percent: percent ? values.base_value : null,
      moq: Number(values.moq),
      tiers: tiersBody(values.tiers),
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

/** Form field → the backend's `details` key, for mapping validation errors. */
export const SERVER_FIELDS: readonly (readonly [keyof ProductFormValues, string])[] = [
  ["name", "name"],
  ["category_id", "category_id"],
  ["description", "description"],
  ["code", "sku.code"],
  ["selling_unit_id", "sku.selling_unit_id"],
  ["base_unit_id", "sku.base_unit_id"],
  ["conversion_factor", "sku.conversion_factor"],
  ["cover_price", "sku.cover_price"],
  ["base_value", "sku.base_price"],
  ["base_value", "sku.base_price_percent"],
  ["moq", "sku.moq"],
  ["opening_stock", "opening_stock"],
];
