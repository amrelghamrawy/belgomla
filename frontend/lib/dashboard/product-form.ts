import { z } from "zod";

import type {
  CategoryKind,
  DashboardProduct,
  DashboardSku,
  DashboardSkuUnit,
  FieldDefinition,
  ProductStatus,
  SkuUnitRequest,
} from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import { type CustomFieldDrafts, customFieldsBody, draftsFromProduct } from "./custom-fields";
import { type TierDraft, tiersBody, toBasisPoints, toPiasters } from "./price-math";
import type { ProductCreateInput } from "./catalog";
import {
  baseCodeOf,
  combinationKey,
  generateVariants,
  MAX_OPTION_TEXT,
  MAX_OPTION_VALUES,
  MAX_OPTIONS,
  type OptionDraft,
} from "./variants";

// The product form (08 §6): values as the inputs hold them, the zod schema mirroring ProductPayloadRules,
// and the conversion to the create/update body. The SKU is sold in one or more units (V2 Task 13, D91), each with
// its own factor, prices, MOQ and tiers; exactly one is the default. A product may have up to two options (V2 Task 14,
// BR-P14, D92): then every combination of their values is a variant SKU with its own code, status, units and stock,
// and the body sends `options` + `skus` instead of `sku`.

const copy = ar.dashboard.productForm;

export const MAX_UNITS = 10;

const CODE = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/;
const isStock = (value: string) => value === "" || /^\d{1,8}$/.test(value);

const integer = (min: number, max: number) =>
  z
    .string()
    .trim()
    .regex(/^\d+$/, copy.invalidInteger)
    .refine((value) => Number(value) >= min && Number(value) <= max, copy.invalidInteger);

const unitSchema = z.object({
  /** The stored unit's id, "" for a new one. */
  id: z.string(),
  // Required, but checked in superRefine: only the units in use (the SKU's, or each variant's) are checked.
  unit_id: z.string(),
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

const optionSchema = z.object({
  /** The stored option's id, "" for a new one. */
  id: z.string(),
  name: z.string(),
  /** Each value with its stored id ("" for new) and the product image chosen as its swatch ("" for none). */
  values: z.array(z.object({ id: z.string(), value: z.string(), image_id: z.string() })),
});

export type OptionFormValues = z.infer<typeof optionSchema>;

const variantSchema = z.object({
  /** The stored SKU's id, "" for a combination this save creates. */
  id: z.string(),
  /** One value of every option, in option order. */
  option_values: z.array(z.string()),
  code: z.string(),
  status: z.enum(["active", "inactive"]),
  units: z.array(unitSchema),
  default_unit: z.number().int().min(0),
  opening_stock: z.string(),
});

export type VariantFormValues = z.infer<typeof variantSchema>;

type Issue = (path: (string | number)[], message: string) => void;

/** One SKU's units: a unit each, none twice, at most ten, a price or a % with a reference price. */
function checkUnits(units: readonly UnitFormValues[], path: (string | number)[], issue: Issue) {
  const seen = new Set<string>();

  if (units.length === 0) {
    issue(path, copy.units.atLeastOne);
  } else if (units.length > MAX_UNITS) {
    issue(path, copy.units.atMost(MAX_UNITS));
  }

  units.forEach((unit, index) => {
    if (unit.unit_id === "") {
      issue([...path, index, "unit_id"], copy.required);
    } else if (seen.has(unit.unit_id)) {
      issue([...path, index, "unit_id"], copy.units.duplicate);
    }

    seen.add(unit.unit_id);

    if (unit.base_mode === "percent") {
      if (unit.cover_price === "") {
        // The schema is shared by every section, so the message names the reference price both ways.
        issue([...path, index, "base_value"], copy.percentNeedsCover(ar.product.referencePrice(null)));
      } else if (toBasisPoints(unit.base_value) === null) {
        issue([...path, index, "base_value"], copy.invalidPercent);
      }
    } else if (toPiasters(unit.base_value) === null || toPiasters(unit.base_value) === 0n) {
      issue([...path, index, "base_value"], copy.invalidMoney);
    }
  });
}

const fold = (text: string) => text.trim().toLowerCase();

/** The options (D92): at most two, each named, with 1..20 values; names and values short and distinct. */
function checkOptions(options: readonly OptionFormValues[], issue: Issue) {
  const names = new Set<string>();

  if (options.length > MAX_OPTIONS) {
    issue(["options"], copy.options.atMost(MAX_OPTIONS));
  }

  options.forEach((option, index) => {
    const name = fold(option.name);

    if (name === "") {
      issue(["options", index, "name"], copy.required);
    } else if (option.name.trim().length > MAX_OPTION_TEXT) {
      issue(["options", index, "name"], copy.options.tooLong(MAX_OPTION_TEXT));
    } else if (names.has(name)) {
      issue(["options", index, "name"], copy.options.nameDuplicate);
    }

    names.add(name);
    const values = new Set<string>();

    if (option.values.length === 0) {
      issue(["options", index, "values"], copy.options.valuesRequired);
    } else if (option.values.length > MAX_OPTION_VALUES) {
      issue(["options", index, "values"], copy.options.valuesAtMost(MAX_OPTION_VALUES));
    }

    for (const { value } of option.values) {
      if (value.trim() === "") {
        issue(["options", index, "values"], copy.required);
      } else if (value.trim().length > MAX_OPTION_TEXT) {
        issue(["options", index, "values"], copy.options.tooLong(MAX_OPTION_TEXT));
      } else if (values.has(fold(value))) {
        issue(["options", index, "values"], copy.options.valueDuplicate);
      }

      values.add(fold(value));
    }
  });
}

/** The variants (BR-P14, BR-P03): a valid code each, no code twice, valid units, at least one active. */
function checkVariants(variants: readonly VariantFormValues[], issue: Issue) {
  const codes = new Set<string>();

  if (variants.length === 0) {
    issue(["variants"], copy.variants.none);
  } else if (!variants.some((variant) => variant.status === "active")) {
    issue(["variants"], copy.variants.atLeastOneActive);
  }

  variants.forEach((variant, index) => {
    const code = variant.code.trim();

    if (code === "") {
      issue(["variants", index, "code"], copy.required);
    } else if (code.length > 60 || !CODE.test(code)) {
      issue(["variants", index, "code"], copy.variants.invalidCode);
    } else if (codes.has(code.toUpperCase())) {
      issue(["variants", index, "code"], copy.variants.codeDuplicate);
    }

    codes.add(code.toUpperCase());

    if (!isStock(variant.opening_stock.trim())) {
      issue(["variants", index, "opening_stock"], copy.invalidInteger);
    }

    checkUnits(variant.units, ["variants", index, "units"], issue);
  });
}

export const productSchema = z
  .object({
    name: z.string().trim().min(1, copy.required).max(200),
    category_id: z.string().min(1, copy.required),
    description: z.string().max(5000),
    status: z.enum(["active", "coming_soon", "inactive"]),
    /** The SKU's code; with options, the base the variant codes are generated from. */
    code: z.string().trim().min(1, copy.required).max(60).regex(CODE, copy.required),
    base_unit_id: z.string().min(1, copy.required),
    /** The SKU's units; with options, what a new variant starts from when there is no other variant to copy. */
    units: z.array(unitSchema),
    /** The index of the default unit in `units`. */
    default_unit: z.number().int().min(0),
    opening_stock: z.string().trim().refine(isStock, copy.invalidInteger),
    /** The stored SKU of a product without options, "" for a new product (V2 Task 14). */
    sku_id: z.string(),
    /** Whether the stored product has options: removing them all still sends `options: []` + `skus`. */
    had_options: z.boolean(),
    options: z.array(optionSchema),
    /** One row per combination of the options' values, in option and value order. */
    variants: z.array(variantSchema),
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
    const issue: Issue = (path, message) => context.addIssue({ code: "custom", path, message });

    if (values.options.length === 0) {
      checkUnits(values.units, ["units"], issue);
      return;
    }

    checkOptions(values.options, issue);
    checkVariants(values.variants, issue);
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

function unitsOf(sku: DashboardSku | null): { units: UnitFormValues[]; default_unit: number } {
  const units = sku?.units ?? [];

  return {
    units: units.length === 0 ? [unitDraft()] : units.map((unit) => unitDraft(unit)),
    default_unit: Math.max(
      0,
      units.findIndex((unit) => unit.is_default),
    ),
  };
}

/** A stored SKU as a variant row. */
function variantDraft(sku: DashboardSku): VariantFormValues {
  return {
    id: String(sku.id),
    option_values: sku.option_values ?? [],
    code: sku.code,
    status: sku.status ?? "active",
    ...unitsOf(sku),
    opening_stock: "",
  };
}

/** The form's starting values: the product's, or a blank new product (active, one unit, no options). */
export function productDefaults(product: DashboardProduct | null): ProductFormValues {
  const sku = product?.sku ?? null;
  const options: OptionFormValues[] = (product?.options ?? []).map((option) => ({
    id: String(option.id),
    name: option.name,
    values: option.values.map((value) => ({
      id: String(value.id),
      value: value.value,
      image_id: value.image_id === null ? "" : String(value.image_id),
    })),
  }));
  const variants = options.length === 0 ? [] : (product?.skus ?? []).map(variantDraft);

  return {
    name: product?.name ?? "",
    category_id: product?.category ? String(product.category.id) : "",
    description: product?.description ?? "",
    status: product?.status ?? "active",
    code: options.length === 0 ? (sku?.code ?? "") : baseCodeOf(optionDrafts(options), variants),
    base_unit_id: sku?.base_unit ? String(sku.base_unit.id) : "",
    ...unitsOf(sku),
    opening_stock: "",
    sku_id: sku === null ? "" : String(sku.id),
    had_options: options.length > 0,
    options,
    variants,
    custom: product === null ? {} : draftsFromProduct(product.custom_fields),
    preorder_enabled: product?.preorder.enabled ?? false,
    expected_at: product?.preorder.expected_at ?? "",
    preorder_limit: product?.preorder.limit === null || product === null ? "" : String(product.preorder.limit),
  };
}

/** The options as the code generator reads them (names and value texts). */
export function optionDrafts(options: readonly OptionFormValues[]): OptionDraft[] {
  return options.map((option) => ({ name: option.name, values: option.values.map((value) => value.value.trim()) }));
}

/** A copy of a SKU's units for another SKU: the target's stored unit ids are kept where the unit matches. */
export function copyUnits(source: readonly UnitFormValues[], target: readonly UnitFormValues[] = []): UnitFormValues[] {
  return source.map((unit) => ({
    ...unit,
    id: target.find((stored) => stored.unit_id !== "" && stored.unit_id === unit.unit_id)?.id ?? "",
    tiers: unit.tiers.map((tier) => ({ ...tier })),
  }));
}

/**
 * The variant rows for the options as they now are (BR-P14): one per combination of the values, in option and value
 * order. A combination that already has a row keeps it (its id, code, status, prices and stock); a new one gets a
 * generated code and starts from the units of the row it shares most values with, else of the first row, else of
 * `template` (the SKU's units). An option without values yet is left out until it gets one.
 */
export function syncVariants(
  current: readonly VariantFormValues[],
  options: readonly OptionFormValues[],
  baseCode: string,
  template: { units: readonly UnitFormValues[]; default_unit: number },
): VariantFormValues[] {
  const usable = optionDrafts(options).filter((option) => option.values.length > 0);
  const byKey = new Map(current.map((row) => [combinationKey(row.option_values), row]));
  const shared = (a: readonly string[], b: readonly string[]) => a.filter((value) => b.includes(value)).length;

  return generateVariants(baseCode, usable).map(({ option_values, code }) => {
    const existing = byKey.get(combinationKey(option_values));

    if (existing !== undefined) {
      return { ...existing, option_values };
    }

    const closest = [...current].sort(
      (a, b) => shared(b.option_values, option_values) - shared(a.option_values, option_values),
    )[0];
    const source = closest ?? template;

    return {
      id: "",
      option_values,
      code,
      status: "active",
      units: copyUnits(source.units),
      default_unit: Math.min(source.default_unit, Math.max(0, source.units.length - 1)),
      opening_stock: "",
    };
  });
}

/** Every variant takes the first one's units and prices («تطبيق على الكل»), keeping its own stored unit ids. */
export function applyFirstToAll(variants: readonly VariantFormValues[]): VariantFormValues[] {
  const first = variants[0];

  if (first === undefined) {
    return [];
  }

  return variants.map((variant, index) =>
    index === 0
      ? variant
      : { ...variant, units: copyUnits(first.units, variant.units), default_unit: first.default_unit },
  );
}

/** The variants' codes generated again from the base code. */
export function regenerateCodes(
  variants: readonly VariantFormValues[],
  options: readonly OptionFormValues[],
  baseCode: string,
): VariantFormValues[] {
  const codes = new Map(
    generateVariants(baseCode, optionDrafts(options)).map((draft) => [combinationKey(draft.option_values), draft.code]),
  );

  return variants.map((variant) => ({
    ...variant,
    code: codes.get(combinationKey(variant.option_values)) ?? variant.code,
  }));
}

function unitsBody(units: readonly UnitFormValues[], defaultUnit: number): SkuUnitRequest[] {
  return units.map((unit, index) => {
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
      is_default: index === defaultUnit,
    };
  });
}

/**
 * The API body. Prices go as the merchant entered them, per unit: `base_price` or `base_price_percent`, and per tier
 * `unit_price` or `discount_percent` (BR-PR10). Custom fields are those of the chosen category's section (D69).
 * `status` and `opening_stock` only on create. The pre-order settings always go (D88: an empty day or cap is none).
 * A product without options sends its one SKU as `sku` (the V1 shape); one with options (or that had them) sends
 * `options` and one `skus` row per variant, each with its own opening stock when the save creates it (BR-P14).
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
    preorder: {
      enabled: values.preorder_enabled,
      expected_at: values.expected_at === "" ? null : values.expected_at,
      limit: values.preorder_limit === "" ? null : Number(values.preorder_limit),
    },
  };
  const baseUnitId = Number(values.base_unit_id);
  const variantMode = values.options.length > 0 || values.had_options;

  if (!variantMode) {
    body.sku = {
      code: values.code.trim(),
      base_unit_id: baseUnitId,
      units: unitsBody(values.units, values.default_unit),
    };
  } else if (values.options.length > 0) {
    body.options = values.options.map((option) => ({
      ...(option.id === "" ? {} : { id: Number(option.id) }),
      name: option.name.trim(),
      values: option.values.map((value) => ({
        ...(value.id === "" ? {} : { id: Number(value.id) }),
        value: value.value.trim(),
        image_id: value.image_id === "" ? null : Number(value.image_id),
      })),
    }));
    body.skus = values.variants.map((variant) => {
      const stock = variant.opening_stock.trim();

      return {
        ...(variant.id === "" ? {} : { id: Number(variant.id) }),
        code: variant.code.trim(),
        base_unit_id: baseUnitId,
        status: variant.status,
        option_values: variant.option_values,
        units: unitsBody(variant.units, variant.default_unit),
        ...(variant.id === "" && stock !== "" ? { opening_stock: Number(stock) } : {}),
      };
    });
  } else {
    // Every option removed: the product goes back to one SKU, the stored one standing for it.
    body.options = [];
    body.skus = [
      {
        ...(values.sku_id === "" ? {} : { id: Number(values.sku_id) }),
        code: values.code.trim(),
        base_unit_id: baseUnitId,
        status: "active",
        option_values: [],
        units: unitsBody(values.units, values.default_unit),
      },
    ];
  }

  if (creating) {
    body.status = values.status as ProductStatus;

    if (!variantMode && values.opening_stock !== "") {
      body.opening_stock = Number(values.opening_stock);
    }
  }

  return body;
}

type UnitField = "unit_id" | "conversion_factor" | "cover_price" | "base_value" | "moq";

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
  | "options"
  | "variants"
  | `units.${number}.${UnitField}`
  | `options.${number}.${"name" | "values"}`
  | `variants.${number}.${"code" | "option_values" | "opening_stock" | "units"}`
  | `variants.${number}.units.${number}.${UnitField}`;

function unitFields(path: string, key: string, index: number): (readonly [FormPath, string])[] {
  const field = (name: UnitField) => `${path}.${index}.${name}` as FormPath;
  const server = `${key}.${index}`;

  return [
    [field("unit_id"), `${server}.unit_id`],
    [field("unit_id"), `${server}.id`],
    [field("conversion_factor"), `${server}.conversion_factor`],
    [field("cover_price"), `${server}.cover_price`],
    [field("base_value"), `${server}.base_price`],
    [field("base_value"), `${server}.base_price_percent`],
    [field("moq"), `${server}.moq`],
  ];
}

/**
 * Form field → the backend's `details` key, for mapping validation errors: the SKU's units' keys for `unitCount`
 * units (`sku.*`), and with options (V2 Task 14) the `options.*` and `skus.*` keys, given each option's value count
 * and each variant's unit count.
 */
export function serverFields(
  unitCount: number,
  variantUnitCounts: readonly number[] = [],
  optionValueCounts: readonly number[] = [],
): (readonly [FormPath, string])[] {
  const fields: (readonly [FormPath, string])[] = [
    ["name", "name"],
    ["category_id", "category_id"],
    ["description", "description"],
    ["code", "sku.code"],
    ["base_unit_id", "sku.base_unit_id"],
    ["base_unit_id", "skus.0.base_unit_id"],
    ["units", "sku.units"],
    ["opening_stock", "opening_stock"],
    ["preorder_enabled", "preorder.enabled"],
    ["expected_at", "preorder.expected_at"],
    ["preorder_limit", "preorder.limit"],
    ["options", "options"],
    ["variants", "skus"],
  ];

  for (let index = 0; index < unitCount; index++) {
    fields.push(...unitFields("units", "sku.units", index));
  }

  optionValueCounts.forEach((count, index) => {
    fields.push(
      [`options.${index}.name`, `options.${index}.name`],
      [`options.${index}.values`, `options.${index}.values`],
    );

    for (let value = 0; value < count; value++) {
      fields.push(
        [`options.${index}.values`, `options.${index}.values.${value}.value`],
        [`options.${index}.values`, `options.${index}.values.${value}.image_id`],
      );
    }
  });

  variantUnitCounts.forEach((count, index) => {
    fields.push(
      [`variants.${index}.code`, `skus.${index}.code`],
      [`variants.${index}.code`, `skus.${index}.status`],
      [`variants.${index}.option_values`, `skus.${index}.option_values`],
      [`variants.${index}.opening_stock`, `skus.${index}.opening_stock`],
      [`variants.${index}.units`, `skus.${index}.units`],
    );

    for (let unit = 0; unit < count; unit++) {
      fields.push(...unitFields(`variants.${index}.units`, `skus.${index}.units`, unit));
    }
  });

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

/**
 * `invalid_tiers` details of a product with options (`skus.{v}.units.{i}.tiers`) → the problems by variant index,
 * then unit index; anything else lands on the first variant's first unit.
 */
export function serverVariantTierProblems(
  details: Record<string, unknown> | null | undefined,
  fallback: string,
): Record<number, Record<number, string[]>> {
  const problems: Record<number, Record<number, string[]>> = {};

  for (const [key, value] of Object.entries(details ?? {})) {
    const match = /^skus\.(\d+)\.units\.(\d+)\.tiers$/.exec(key);
    const variant = match === null ? 0 : Number(match[1]);
    const unit = match === null ? 0 : Number(match[2]);
    const messages = Array.isArray(value) ? value.map(String) : [String(value)];
    const forVariant = (problems[variant] ??= {});
    forVariant[unit] = [...(forVariant[unit] ?? []), ...messages];
  }

  return Object.keys(problems).length === 0 ? { 0: { 0: [fallback] } } : problems;
}
