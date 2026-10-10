import { describe, expect, it } from "vitest";

import { bulkFilterOf } from "@/lib/dashboard/catalog";
import { activeOnly, applyOrder, flatten, reorderSiblings, subtreeIds } from "@/lib/dashboard/category-tree";
import { customFieldProblems, customFieldsBody, visibleDefinitions } from "@/lib/dashboard/custom-fields";
import { productBody, productDefaults } from "@/lib/dashboard/product-form";

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
    expect(customFieldsBody(definitions, { grade: "12", pages: "120", color: "false", old: "" })).toEqual({
      grade: 12,
      pages: 120,
      color: false,
    });
  });

  it("shows archived fields only while the product holds a value", () => {
    expect(visibleDefinitions(definitions, {}).map((definition) => definition.key)).toEqual([
      "grade",
      "pages",
      "color",
    ]);
    expect(visibleDefinitions(definitions, { old: "x" }).map((definition) => definition.key)).toContain("old");
  });

  it("flags a missing required field and a number that is not one", () => {
    expect(customFieldProblems(definitions, { pages: "abc" }, { required: "مطلوب", number: "رقم" })).toEqual({
      grade: "مطلوب",
      pages: "رقم",
    });
  });
});

describe("product form", () => {
  it("BR-PR10 starts from the product with the price as it was entered: percent stays percent", () => {
    const values = productDefaults(dashboardProduct());

    expect(values).toMatchObject({
      base_mode: "percent",
      base_value: "25.00",
      cover_price: "120.00",
      custom: { grade: "11" },
    });
    expect(values.tiers).toEqual([{ min_quantity: "10", mode: "percent", value: "30.00" }]);
  });

  it("sends status and opening stock on create only", () => {
    const values = { ...productDefaults(dashboardProduct()), opening_stock: "100" };

    expect(productBody(values, [selectField()], true)).toMatchObject({ status: "active", opening_stock: 100 });
    expect(productBody(values, [selectField()], false)).not.toHaveProperty("opening_stock");
    expect(productBody(values, [selectField()], false).sku).toMatchObject({
      base_price: null,
      base_price_percent: "25.00",
      tiers: [{ min_quantity: 10, discount_percent: "30.00" }],
    });
  });
});
