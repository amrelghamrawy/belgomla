import { describe, expect, it } from "vitest";

import {
  applyFirstToAll,
  productBody,
  productDefaults,
  productSchema,
  regenerateCodes,
  serverFields,
  serverVariantTierProblems,
  syncVariants,
  unitDraft,
  type OptionFormValues,
  type ProductFormValues,
} from "@/lib/dashboard/product-form";
import {
  baseCodeOf,
  combinationKey,
  combinations,
  generateVariants,
  valueSlug,
  variantLabel,
} from "@/lib/dashboard/variants";

import { dashboardProduct, selectField, variantProduct } from "../../dashboard-fixtures";

const colours = { name: "اللون", values: ["أزرق", "أسود", "أحمر"] };
const sizes = { name: "المقاس", values: ["S", "M"] };

function option(name: string, values: string[], id = ""): OptionFormValues {
  return { id, name, values: values.map((value) => ({ id: "", value, image_id: "" })) };
}

describe("valueSlug", () => {
  it("uppercases ASCII letters and digits and collapses other runs to one dash", () => {
    expect(valueSlug("Navy blue", 1)).toBe("NAVY-BLUE");
    expect(valueSlug("  x/l  ", 1)).toBe("X-L");
    expect(valueSlug("A4 (70g)", 1)).toBe("A4-70G");
  });

  it("uses the value's position for a value with nothing ASCII (Arabic)", () => {
    expect(valueSlug("أزرق", 1)).toBe("1");
    expect(valueSlug("أحمر", 3)).toBe("3");
  });
});

describe("generateVariants", () => {
  it("BR-P14 makes one SKU per combination, the first option varying slowest", () => {
    expect(combinations([colours, sizes])).toEqual([
      ["أزرق", "S"],
      ["أزرق", "M"],
      ["أسود", "S"],
      ["أسود", "M"],
      ["أحمر", "S"],
      ["أحمر", "M"],
    ]);
    expect(combinations([])).toEqual([]);
  });

  it("codes each variant {base}-{slug1}-{slug2}, Arabic values by position", () => {
    expect(generateVariants(" PEN-01 ", [colours, sizes]).map((variant) => variant.code)).toEqual([
      "PEN-01-1-S",
      "PEN-01-1-M",
      "PEN-01-2-S",
      "PEN-01-2-M",
      "PEN-01-3-S",
      "PEN-01-3-M",
    ]);
    expect(generateVariants("BAG", [{ name: "Colour", values: ["Navy blue"] }])).toEqual([
      { option_values: ["Navy blue"], code: "BAG-NAVY-BLUE" },
    ]);
  });

  it("labels a combination with its values joined by a slash", () => {
    expect(variantLabel(["أزرق", "M"])).toBe("أزرق / M");
    expect(combinationKey([" أزرق", "M "])).toBe(combinationKey(["أزرق", "M"]));
  });

  it("finds the base code the variant codes were generated from", () => {
    const skus = generateVariants("PEN-01", [colours, sizes]);

    expect(baseCodeOf([colours, sizes], skus)).toBe("PEN-01");
    expect(
      baseCodeOf(
        [colours],
        [
          { code: "X-BLUE", option_values: ["أزرق"] },
          { code: "X-BLACK", option_values: ["أسود"] },
        ],
      ),
    ).toBe("X");
    expect(baseCodeOf([colours], [])).toBe("");
  });
});

describe("variant rows in the product form", () => {
  const template = { units: [{ ...unitDraft(), unit_id: "2", base_value: "10.00" }], default_unit: 0 };

  it("BR-P14 generates a row per combination with the SKU's units as a start", () => {
    const rows = syncVariants([], [option("اللون", ["أزرق", "أسود"])], "PEN", template);

    expect(rows.map((row) => [row.option_values, row.code, row.status, row.id])).toEqual([
      [["أزرق"], "PEN-1", "active", ""],
      [["أسود"], "PEN-2", "active", ""],
    ]);
    expect(rows[1]?.units[0]).toMatchObject({ unit_id: "2", base_value: "10.00", id: "" });
  });

  it("keeps the row of a combination that stays and copies the closest row for a new one", () => {
    const first = syncVariants([], [option("اللون", ["أزرق", "أسود"])], "PEN", template);
    const edited = first.map((row, index) =>
      index === 0
        ? { ...row, id: "5", code: "MINE", units: [{ ...row.units[0]!, id: "9", base_value: "11.00" }] }
        : row,
    );
    const next = syncVariants(edited, [option("اللون", ["أزرق"]), option("المقاس", ["S", "M"])], "PEN", template);

    expect(next.map((row) => row.option_values)).toEqual([
      ["أزرق", "S"],
      ["أزرق", "M"],
    ]);
    // A new combination copies the prices of the row it shares a value with, never its ids.
    expect(next[0]).toMatchObject({ id: "", code: "PEN-1-S" });
    expect(next[0]?.units[0]).toMatchObject({ id: "", base_value: "11.00" });

    const back = syncVariants(next, [option("اللون", ["أزرق"]), option("المقاس", ["S"])], "PEN", template);
    expect(back).toEqual([next[0]]);
  });

  it("leaves out an option until it has a value", () => {
    const rows = syncVariants([], [option("اللون", ["أزرق"]), option("المقاس", [])], "PEN", template);

    expect(rows.map((row) => row.option_values)).toEqual([["أزرق"]]);
  });

  it("applies the first row's units and prices to every row, keeping each row's stored unit ids", () => {
    const rows = syncVariants([], [option("اللون", ["أزرق", "أسود"])], "PEN", template);
    const stored = [
      { ...rows[0]!, units: [{ ...rows[0]!.units[0]!, base_value: "15.00" }] },
      { ...rows[1]!, id: "8", units: [{ ...rows[1]!.units[0]!, id: "33", base_value: "1.00" }] },
    ];

    expect(applyFirstToAll(stored)[1]?.units).toEqual([{ ...stored[0]!.units[0]!, id: "33" }]);
    expect(applyFirstToAll([])).toEqual([]);
  });

  it("generates the codes again from a new base code", () => {
    const options = [option("اللون", ["أزرق", "أسود"])];
    const rows = syncVariants([], options, "PEN", template);

    expect(regenerateCodes(rows, options, "INK").map((row) => row.code)).toEqual(["INK-1", "INK-2"]);
  });
});

describe("the product form with options", () => {
  function withOptions(): ProductFormValues {
    const values = productDefaults(dashboardProduct());
    const options = [option("اللون", ["أزرق", "أسود"])];

    return { ...values, options, variants: syncVariants([], options, "PEN", values) };
  }

  it("reads a stored product's options and variants, and the base code of their codes", () => {
    const values = productDefaults(variantProduct());

    expect(values.code).toBe("PEN-01");
    expect(values.had_options).toBe(true);
    expect(values.options).toEqual([
      {
        id: "31",
        name: "اللون",
        values: [
          { id: "41", value: "أزرق", image_id: "" },
          { id: "42", value: "أسود", image_id: "7" },
        ],
      },
    ]);
    expect(values.variants.map((row) => [row.id, row.code, row.status, row.option_values])).toEqual([
      ["21", "PEN-01-1", "active", ["أزرق"]],
      ["22", "PEN-01-2", "inactive", ["أسود"]],
    ]);
    expect(productDefaults(dashboardProduct())).toMatchObject({ options: [], variants: [], had_options: false });
  });

  it("BR-P14 sends options and one skus row per variant, with opening stock only for new ones", () => {
    const stored = productDefaults(variantProduct());
    const values: ProductFormValues = {
      ...stored,
      variants: [...stored.variants, { ...stored.variants[0]!, id: "", code: "PEN-01-3", opening_stock: "40" }],
      opening_stock: "99",
    };
    const body = productBody(values, [selectField()], true, "books");

    expect(body).not.toHaveProperty("sku");
    expect(body).not.toHaveProperty("opening_stock");
    expect(body.options).toEqual([
      {
        id: 31,
        name: "اللون",
        values: [
          { id: 41, value: "أزرق", image_id: null },
          { id: 42, value: "أسود", image_id: 7 },
        ],
      },
    ]);
    expect(body.skus).toMatchObject([
      { id: 21, code: "PEN-01-1", base_unit_id: 1, status: "active", option_values: ["أزرق"] },
      { id: 22, code: "PEN-01-2", status: "inactive", option_values: ["أسود"] },
      { code: "PEN-01-3", opening_stock: 40 },
    ]);
    expect(body.skus?.[0]).not.toHaveProperty("opening_stock");
    expect(body.skus?.[0]?.units[0]).toMatchObject({
      id: 21,
      unit_id: 2,
      base_price_percent: "25.00",
      is_default: true,
    });
  });

  it("sends options: [] and the one SKU when every option of a stored product is removed", () => {
    const values = { ...productDefaults(variantProduct()), options: [], variants: [] };

    expect(productBody(values, [], false, "books")).toMatchObject({
      options: [],
      skus: [{ id: 21, code: "PEN-01", option_values: [], status: "active" }],
    });
  });

  it("keeps the V1 sku body for a product without options", () => {
    const body = productBody(productDefaults(dashboardProduct()), [selectField()], false, "books");

    expect(body.sku).toMatchObject({ code: "ADW-SCI-1" });
    expect(body).not.toHaveProperty("options");
    expect(body).not.toHaveProperty("skus");
  });

  it("D92 refuses a third option, an unnamed or repeated option, and a repeated value", () => {
    const values = withOptions();
    const result = productSchema.safeParse({
      ...values,
      options: [option("اللون", ["أزرق", "أزرق "]), option("اللون", ["S"]), option("", ["x"])],
    });
    const issues = result.error?.issues.map((issue) => [issue.path.join("."), issue.message]);

    expect(issues).toEqual(
      expect.arrayContaining([
        ["options", "2 خيارات على الأكثر"],
        ["options.0.values", "هذه القيمة موجودة بالفعل"],
        ["options.1.name", "اسم الخيار مكرر"],
        ["options.2.name", "مطلوب"],
      ]),
    );
  });

  it("refuses an option without values", () => {
    const result = productSchema.safeParse({ ...withOptions(), options: [option("اللون", [])] });

    expect(result.error?.issues.map((issue) => issue.path.join("."))).toContain("options.0.values");
  });

  it("BR-P03 refuses a missing, malformed or repeated code and needs one active variant", () => {
    const values = withOptions();
    const [blue, black] = values.variants;
    const result = productSchema.safeParse({
      ...values,
      variants: [
        { ...blue!, code: "pen-x", status: "inactive" },
        { ...black!, code: "PEN-X", status: "inactive" },
        { ...black!, code: "", status: "inactive", option_values: ["أحمر"] },
        { ...black!, code: "-bad", status: "inactive", option_values: ["أخضر"], opening_stock: "x" },
      ],
    });
    const issues = result.error?.issues.map((issue) => [issue.path.join("."), issue.message]);

    expect(issues).toEqual(
      expect.arrayContaining([
        ["variants", "اجعل متغيرًا واحدًا على الأقل نشطًا"],
        ["variants.1.code", "هذا الكود مكرر"],
        ["variants.2.code", "مطلوب"],
        ["variants.3.code", "حروف إنجليزية وأرقام و . _ / - فقط، تبدأ بحرف أو رقم"],
        ["variants.3.opening_stock", "رقم صحيح أكبر من صفر"],
      ]),
    );
  });

  it("checks each variant's units, not the SKU's, once options exist", () => {
    const values = withOptions();
    const result = productSchema.safeParse({
      ...values,
      units: [unitDraft()],
      variants: values.variants.map((row, index) =>
        index === 1 ? { ...row, units: [{ ...row.units[0]!, base_mode: "price" as const, base_value: "" }] } : row,
      ),
    });

    expect(result.error?.issues.map((issue) => issue.path.join("."))).toEqual(["variants.1.units.0.base_value"]);
    expect(productSchema.safeParse({ ...values, variants: [] }).error?.issues[0]?.path).toEqual(["variants"]);
  });

  it("maps the skus.* and options.* error keys to the variant and option fields", () => {
    const fields = serverFields(1, [2], [3]);

    expect(fields).toContainEqual(["variants.0.code", "skus.0.code"]);
    expect(fields).toContainEqual(["variants.0.units.1.base_value", "skus.0.units.1.base_price"]);
    expect(fields).toContainEqual(["variants.0.option_values", "skus.0.option_values"]);
    expect(fields).toContainEqual(["options.0.name", "options.0.name"]);
    expect(fields).toContainEqual(["options.0.values", "options.0.values.2.value"]);
    expect(fields).toContainEqual(["variants", "skus"]);
  });

  it("groups invalid_tiers problems by variant and unit", () => {
    expect(serverVariantTierProblems({ "skus.2.units.1.tiers": ["مكرر"] }, "x")).toEqual({ 2: { 1: ["مكرر"] } });
    expect(serverVariantTierProblems(null, "شرائح غير صحيحة")).toEqual({ 0: { 0: ["شرائح غير صحيحة"] } });
  });
});
