import { describe, expect, it } from "vitest";

import { bulkFilterOf } from "@/lib/dashboard/catalog";
import {
  activeOnly,
  applyOrder,
  flatten,
  kindOfCategory,
  reorderSiblings,
  searchCategories,
  searchKey,
  subtreeIds,
} from "@/lib/dashboard/category-tree";
import { customFieldProblems, customFieldsBody, visibleDefinitions } from "@/lib/dashboard/custom-fields";
import {
  productBody,
  productDefaults,
  productSchema,
  serverFields,
  serverTierProblems,
} from "@/lib/dashboard/product-form";

import { category, dashboardProduct, selectField } from "../../dashboard-fixtures";

const tree = [
  category({
    id: 1,
    sort_order: 1,
    children: [
      category({ id: 3, name: "رابعة", parent_id: 1, sort_order: 0, children: [category({ id: 5, parent_id: 3 })] }),
    ],
  }),
  category({ id: 2, name: "الإعدادية", sort_order: 0, status: "archived" }),
];

describe("category tree", () => {
  it("hides archived nodes and lists the rest depth-first in sort order", () => {
    expect(flatten(activeOnly(tree)).map(({ node, depth }) => [node.id, depth])).toEqual([
      [1, 0],
      [3, 1],
      [5, 2],
    ]);
  });

  it("gives every row the names of its ancestors, root first", () => {
    expect(flatten(activeOnly(tree)).map(({ path }) => path)).toEqual([
      [],
      ["المرحلة الابتدائية"],
      ["المرحلة الابتدائية", "رابعة"],
    ]);
  });

  it("searches categories by their own or an ancestor's name, ignoring hamza, ta marbuta and diacritics", () => {
    const rows = flatten(activeOnly(tree));
    const ids = (query: string) => searchCategories(rows, query).map(({ node }) => node.id);

    expect(ids("")).toEqual([1, 3, 5]);
    expect(ids("رابعه")).toEqual([3, 5]);
    expect(ids("الابتدائيَة")).toEqual([1, 3, 5]);
    expect(ids("إعدادي")).toEqual([]);
    expect(searchKey("  أولى إعداديّة ")).toBe("اولي اعداديه");
  });

  it("knows a node's subtree, which cannot become its parent", () => {
    expect([...subtreeIds(tree[0]!)]).toEqual([1, 3, 5]);
  });

  it("moves a node within its siblings and renumbers them", () => {
    expect(reorderSiblings(tree, 2, 1)).toEqual([
      { id: 1, sort_order: 0 },
      { id: 2, sort_order: 1 },
    ]);
    expect(reorderSiblings(tree, 1, 1)).toBeNull();
    expect(applyOrder(tree, [{ id: 3, sort_order: 7 }])[0]?.children[0]?.sort_order).toBe(7);
  });
});

describe("bulkFilterOf", () => {
  it("BR-P12 turns the list query into the bulk filter, facets included", () => {
    expect(bulkFilterOf({ q: "علوم", category: "4", status: "active", "f[grade]": "11", page: "2" })).toEqual({
      filter: { q: "علوم", category: 4, status: "active", f: { grade: "11" } },
    });
  });

  it("refuses an empty filter and the low-stock filter, which the bulk API does not have", () => {
    expect(bulkFilterOf({ page: "3" })).toEqual({ blocked: "empty" });
    expect(bulkFilterOf({ q: "علوم", low_stock: "1" })).toEqual({ blocked: "low_stock" });
  });
});

describe("custom fields", () => {
  const definitions = [
    selectField(),
    selectField({
      id: 2,
      key: "pages",
      label: "الصفحات",
      type: "number",
      is_required: false,
      sort_order: 2,
      options: [],
    }),
    selectField({
      id: 3,
      key: "color",
      label: "ملون",
      type: "boolean",
      is_required: false,
      sort_order: 3,
      options: [],
    }),
    selectField({
      id: 4,
      key: "old",
      label: "قديم",
      type: "text",
      status: "archived",
      is_required: false,
      options: [],
    }),
  ];

  it("converts drafts to API values and leaves empty ones out", () => {
    expect(customFieldsBody(definitions, { grade: "12", pages: "120", color: "false", old: "" }, "general")).toEqual({
      grade: 12,
      pages: 120,
      color: false,
    });
  });

  it("shows archived fields only while the product holds a value", () => {
    expect(visibleDefinitions(definitions, {}, "general").map((definition) => definition.key)).toEqual([
      "grade",
      "pages",
      "color",
    ]);
    expect(visibleDefinitions(definitions, { old: "x" }, "general").map((definition) => definition.key)).toContain(
      "old",
    );
  });

  it("flags a missing required field and a number that is not one", () => {
    expect(customFieldProblems(definitions, { pages: "abc" }, "general", { required: "مطلوب", number: "رقم" })).toEqual(
      {
        grade: "مطلوب",
        pages: "رقم",
      },
    );
  });

  describe("D69 fields bound to a section", () => {
    const sectioned = [
      selectField({ id: 1, key: "grade", kind: "books" }),
      selectField({ id: 5, key: "age", label: "العمر", type: "text", kind: "toys", is_required: true, options: [] }),
      selectField({ id: 6, key: "origin", label: "المنشأ", type: "text", kind: null, is_required: false, options: [] }),
    ];

    it("shows only the fields of the product's section and the section-less ones", () => {
      expect(visibleDefinitions(sectioned, {}, "toys").map((definition) => definition.key)).toEqual(["age", "origin"]);
      expect(visibleDefinitions(sectioned, { grade: "11" }, "toys").map((definition) => definition.key)).toEqual([
        "age",
        "origin",
      ]);
    });

    it("shows only the section-less fields until a category is chosen", () => {
      expect(visibleDefinitions(sectioned, {}, null).map((definition) => definition.key)).toEqual(["origin"]);
    });

    it("leaves the values of another section's fields out of the body", () => {
      expect(customFieldsBody(sectioned, { grade: "11", age: "3+", origin: "مصر" }, "toys")).toEqual({
        age: "3+",
        origin: "مصر",
      });
    });

    it("requires only the fields of the product's section", () => {
      expect(customFieldProblems(sectioned, {}, "books", { required: "مطلوب", number: "رقم" })).toEqual({
        grade: "مطلوب",
      });
      expect(customFieldProblems(sectioned, {}, "toys", { required: "مطلوب", number: "رقم" })).toEqual({
        age: "مطلوب",
      });
    });
  });
});

describe("kindOfCategory", () => {
  const tree = [
    category({ id: 1, kind: "books", children: [category({ id: 3, name: "رابعة", parent_id: 1, kind: "books" })] }),
    category({ id: 2, name: "ألعاب", kind: "toys", status: "archived" }),
  ];

  it("D68 finds the kind anywhere in the tree, archived nodes included", () => {
    expect(kindOfCategory(tree, 3)).toBe("books");
    expect(kindOfCategory(tree, 2)).toBe("toys");
  });

  it("is null without a category or for an unknown one", () => {
    expect(kindOfCategory(tree, null)).toBeNull();
    expect(kindOfCategory(tree, 99)).toBeNull();
  });
});

describe("product form", () => {
  it("BR-PR10 starts from the product with the price as it was entered: percent stays percent", () => {
    const values = productDefaults(dashboardProduct());

    expect(values).toMatchObject({ custom: { grade: "11" }, base_unit_id: "1", default_unit: 0 });
    expect(values.units).toEqual([
      {
        id: "5",
        unit_id: "2",
        conversion_factor: "20",
        cover_price: "120.00",
        base_mode: "percent",
        base_value: "25.00",
        moq: "5",
        tiers: [{ min_quantity: "10", mode: "percent", value: "30.00" }],
      },
    ]);
  });

  it("D91 starts a new product with one blank unit and sends every unit with the one default", () => {
    expect(productDefaults(null).units).toEqual([
      {
        id: "",
        unit_id: "",
        conversion_factor: "1",
        cover_price: "",
        base_mode: "price",
        base_value: "",
        moq: "1",
        tiers: [],
      },
    ]);

    const values = {
      ...productDefaults(dashboardProduct()),
      units: [
        ...productDefaults(dashboardProduct()).units,
        {
          id: "",
          unit_id: "4",
          conversion_factor: "12",
          cover_price: "",
          base_mode: "price" as const,
          base_value: "1000",
          moq: "1",
          tiers: [],
        },
      ],
      default_unit: 1,
    };

    expect(productBody(values, [selectField()], false, "books").sku).toEqual({
      code: "ADW-SCI-1",
      base_unit_id: 1,
      units: [
        {
          id: 5,
          unit_id: 2,
          conversion_factor: 20,
          cover_price: "120.00",
          base_price: null,
          base_price_percent: "25.00",
          moq: 5,
          tiers: [{ min_quantity: 10, discount_percent: "30.00" }],
          is_default: false,
        },
        {
          unit_id: 4,
          conversion_factor: 12,
          cover_price: null,
          base_price: "1000",
          base_price_percent: null,
          moq: 1,
          tiers: [],
          is_default: true,
        },
      ],
    });
  });

  it("D91 refuses the same unit twice and a missing price per unit", () => {
    const base = productDefaults(dashboardProduct());
    const twice = productSchema.safeParse({ ...base, units: [base.units[0], base.units[0]] });
    const unpriced = productSchema.safeParse({
      ...base,
      units: [{ ...base.units[0], base_mode: "price", base_value: "" }],
    });

    expect(twice.success).toBe(false);
    expect(twice.error?.issues.map((issue) => issue.path.join("."))).toContain("units.1.unit_id");
    expect(unpriced.error?.issues.map((issue) => issue.path.join("."))).toContain("units.0.base_value");
  });

  it("D91 maps the server's per-unit keys and tier problems to the unit", () => {
    expect(serverFields(2)).toContainEqual(["units.1.base_value", "sku.units.1.base_price"]);
    expect(serverTierProblems({ "sku.units.1.tiers": ["مكرر"] }, "x")).toEqual({ 1: ["مكرر"] });
    expect(serverTierProblems(null, "شرائح غير صحيحة")).toEqual({ 0: ["شرائح غير صحيحة"] });
  });

  it("D88 starts from the product's pre-order settings and sends them, an empty day or cap as none", () => {
    const values = productDefaults(
      dashboardProduct({ preorder: { enabled: true, expected_at: "2026-12-01", limit: 40 } }),
    );

    expect(values).toMatchObject({ preorder_enabled: true, expected_at: "2026-12-01", preorder_limit: "40" });
    expect(
      productBody({ ...values, expected_at: "", preorder_limit: "" }, [selectField()], false, "books").preorder,
    ).toEqual({
      enabled: true,
      expected_at: null,
      limit: null,
    });
    expect(productDefaults(null)).toMatchObject({ preorder_enabled: false, expected_at: "", preorder_limit: "" });
  });

  it("sends status and opening stock on create only", () => {
    const values = { ...productDefaults(dashboardProduct()), opening_stock: "100" };

    expect(productBody(values, [selectField()], true, "books")).toMatchObject({ status: "active", opening_stock: 100 });
    expect(productBody(values, [selectField()], false, "books")).not.toHaveProperty("opening_stock");
    expect(productBody(values, [selectField()], false, "books").sku?.units[0]).toMatchObject({
      base_price: null,
      base_price_percent: "25.00",
      tiers: [{ min_quantity: 10, discount_percent: "30.00" }],
    });
  });
});
