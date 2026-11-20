import { describe, expect, it } from "vitest";

import type { FieldDefinition } from "@/lib/api/types";
import {
  addComponent,
  bundleBody,
  bundleDefaults,
  bundleSchema,
  bundleTotals,
  type ComponentDraft,
  componentFrom,
  componentQuantity,
  fixedPrice,
  gradeSetFilter,
  gradeSetSelection,
  MAX_COMPONENTS,
  moveComponent,
  percentPoints,
  pickableUnits,
} from "@/lib/dashboard/bundles";

import { bundleDashboardProduct, dashboardProduct, dashboardSkuUnit, variantProduct } from "../../dashboard-fixtures";

function draft(overrides: Partial<ComponentDraft> = {}): ComponentDraft {
  return {
    sku_unit_id: 5,
    quantity: "1",
    product_name: "الأضواء علوم",
    sku_code: "ADW-SCI-1",
    label: null,
    unit: "كرتونة",
    conversion_factor: 20,
    base_price: "90.00",
    available: 400,
    orderable: true,
    ...overrides,
  };
}

describe("bundleTotals (BR-P15, D93)", () => {
  const components = [
    { base_price: "90.00", quantity: "1" },
    { base_price: "10.10", quantity: "2" },
  ];

  it("sums the picked units' base prices × quantities and takes a fixed price as it is", () => {
    expect(bundleTotals(components, { mode: "fixed", value: "100" })).toEqual({
      sum: "110.20",
      price: "100.00",
      saving: "10.20",
    });
  });

  it("takes the % off the sum, rounded half-up to the piaster like the server", () => {
    // 110.20 × 0.875 = 96.425 → 96.43
    expect(bundleTotals(components, { mode: "percent_off_sum", value: "12.5" })).toEqual({
      sum: "110.20",
      price: "96.43",
      saving: "13.77",
    });
  });

  it("allows 0 % (a generated grade set): the price is the sum and nothing is saved", () => {
    expect(bundleTotals(components, { mode: "percent_off_sum", value: "0" })).toMatchObject({
      price: "110.20",
      saving: "0.00",
    });
  });

  it("never shows a negative saving when the fixed price is above the sum", () => {
    expect(bundleTotals(components, { mode: "fixed", value: "150.00" }).saving).toBe("0.00");
  });

  it("has no price or saving while the value is not valid", () => {
    for (const pricing of [
      { mode: "fixed" as const, value: "0" },
      { mode: "fixed" as const, value: "abc" },
      { mode: "percent_off_sum" as const, value: "100" },
      { mode: "percent_off_sum" as const, value: "" },
    ]) {
      expect(bundleTotals(components, pricing)).toEqual({ sum: "110.20", price: null, saving: null });
    }
  });

  it("counts a quantity that is not a whole number from 1 to 1000 as nothing", () => {
    expect(
      bundleTotals([{ base_price: "90.00", quantity: "x" }, ...components.slice(1)], { mode: "fixed", value: "1" }).sum,
    ).toBe("20.20");
    expect(componentQuantity("1001")).toBeNull();
    expect(componentQuantity("0")).toBeNull();
    expect(componentQuantity(" 3 ")).toBe(3);
  });

  it("reads percentages from 0 to below 100 and prices from 0.01", () => {
    expect(percentPoints("0")).toBe(0n);
    expect(percentPoints("99.99")).toBe(9999n);
    expect(percentPoints("-1")).toBeNull();
    expect(fixedPrice("0.01")).toBe(1n);
    expect(fixedPrice("0.00")).toBeNull();
    expect(fixedPrice("1.234")).toBeNull();
  });
});

describe("bundle components", () => {
  it("offers every unit of every active variant of an active simple product", () => {
    const pen = variantProduct();
    const options = pickableUnits(pen);

    // The black pen is inactive: only the blue one's unit.
    expect(options.map((option) => option.unit.id)).toEqual([21]);
    expect(componentFrom(pen, options[0]!)).toMatchObject({
      sku_unit_id: 21,
      quantity: "1",
      product_name: "قلم جاف",
      sku_code: "PEN-01-1",
      label: "أزرق",
      base_price: "90.00",
      available: 400,
    });
  });

  it("lists each selling unit of a SKU sold in several (D91)", () => {
    const product = dashboardProduct();
    const withCarton = dashboardProduct({
      sku: { ...product.sku!, units: [...product.sku!.units!, dashboardSkuUnit({ id: 9 })] },
      skus: [],
    });

    expect(pickableUnits(withCarton).map((option) => option.unit.id)).toEqual([5, 9]);
  });

  it("D93 offers nothing of a bundle or of an inactive product", () => {
    expect(pickableUnits(bundleDashboardProduct({ status: "active" }))).toEqual([]);
    expect(pickableUnits(dashboardProduct({ status: "inactive" }))).toEqual([]);
  });

  it("adds a unit once, at most 50", () => {
    const one = [draft()];

    expect(addComponent(one, draft())).toBe(one);
    expect(addComponent(one, draft({ sku_unit_id: 6 })).map((row) => row.sku_unit_id)).toEqual([5, 6]);

    const full = Array.from({ length: MAX_COMPONENTS }, (_, index) => draft({ sku_unit_id: index + 100 }));
    expect(addComponent(full, draft({ sku_unit_id: 1 }))).toBe(full);
  });

  it("moves a row up or down and leaves the ends as they are", () => {
    expect(moveComponent(["a", "b", "c"], 2, -1)).toEqual(["a", "c", "b"]);
    expect(moveComponent(["a", "b", "c"], 0, 1)).toEqual(["b", "a", "c"]);
    expect(moveComponent(["a", "b"], 0, -1)).toEqual(["a", "b"]);
    expect(moveComponent(["a", "b"], 1, 1)).toEqual(["a", "b"]);
  });
});

describe("the bundle form", () => {
  it("starts from the stored bundle on edit", () => {
    const values = bundleDefaults(bundleDashboardProduct());

    expect(values).toMatchObject({
      name: "طقم أولى إعدادي",
      code: "SET-1",
      unit_id: "7",
      moq: "1",
      status: "inactive",
      pricing: { mode: "percent_off_sum", value: "10.00" },
    });
    expect(values.components.map((row) => [row.sku_unit_id, row.quantity, row.available, row.orderable])).toEqual([
      [5, "1", 400, true],
      [21, "2", 6, false],
    ]);
  });

  it("starts a new bundle empty at 0 % off", () => {
    expect(bundleDefaults(null)).toMatchObject({ name: "", components: [], status: "active", moq: "1" });
    expect(bundleDefaults(null).pricing).toEqual({ mode: "percent_off_sum", value: "0" });
  });

  it("sends the status on create only and the stored custom fields back on edit", () => {
    const product = bundleDashboardProduct();
    const values = { ...bundleDefaults(product), moq: "", description: "  " };

    expect(bundleBody(values, null)).toEqual({
      name: "طقم أولى إعدادي",
      category_id: 1,
      description: null,
      code: "SET-1",
      unit_id: 7,
      moq: 1,
      components: [
        { sku_unit_id: 5, quantity: 1 },
        { sku_unit_id: 21, quantity: 2 },
      ],
      pricing: { mode: "percent_off_sum", value: "10.00" },
      status: "inactive",
    });
    const edit = bundleBody(values, product);
    expect(edit.status).toBeUndefined();
    expect(edit.custom_fields).toEqual({ grade: 11 });
  });

  it("refuses no component, a bad quantity, a bad code and a price out of range", () => {
    const values = bundleDefaults(null);
    const result = bundleSchema.safeParse({
      ...values,
      name: "باقة",
      category_id: "1",
      unit_id: "7",
      code: "سيت",
      pricing: { mode: "fixed", value: "0" },
    });

    expect(result.success).toBe(false);
    const paths = result.error?.issues.map((issue) => issue.path.join(".")) ?? [];
    expect(paths).toEqual(expect.arrayContaining(["code", "components", "pricing.value"]));

    const quantity = bundleSchema.safeParse({
      ...values,
      name: "باقة",
      category_id: "1",
      unit_id: "7",
      code: "SET-2",
      components: [draft({ quantity: "0" })],
      pricing: { mode: "percent_off_sum", value: "100" },
    });
    expect(quantity.error?.issues.map((issue) => issue.path.join("."))).toEqual(
      expect.arrayContaining(["components.0.quantity", "pricing.value"]),
    );
  });
});

describe("the grade set wizard filter", () => {
  const facets = [
    { key: "grade", type: "select", options: [] },
    { key: "term", type: "select", options: [] },
  ] as unknown as FieldDefinition[];

  it("sends the category and the chosen facets, and nothing when nothing is chosen", () => {
    expect(gradeSetFilter("3", { grade: "7", term: "" })).toEqual({ category: 3, f: { grade: "7" } });
    expect(gradeSetFilter("", { grade: "7" })).toEqual({ f: { grade: "7" } });
    expect(gradeSetFilter("", { grade: "", term: "" })).toBeNull();
  });

  it("starts from the products list's category and facets", () => {
    expect(gradeSetSelection({ category: "3", "f[grade]": "7", q: "علوم" }, facets)).toEqual({
      category: "3",
      facets: { grade: "7", term: "" },
    });
  });
});
