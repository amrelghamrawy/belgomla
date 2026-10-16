import { describe, expect, it } from "vitest";

import { CATEGORY_ICONS, iconFor, iconFromName, normalize } from "@/lib/store/category-icon";
import { ar } from "@/lib/copy/ar";

describe("normalize", () => {
  it("folds alef forms, taa marbuta, alef maqsura, diacritics, tatweel and Latin case", () => {
    expect(normalize("أإآة ى")).toBe("اااه ي");
    expect(normalize("كُـتُب KG")).toBe("كتب kg");
  });
});

describe("iconFromName", () => {
  it.each([
    ["كتب خارجية", "book-open"],
    ["أقلام", "pencil"],
    ["أقلام جاف", "pen"],
    ["أقلام ألوان", "palette"],
    ["كراسات", "notebook-pen"],
    ["كشاكيل", "notebook-pen"],
    ["آلات حاسبة", "calculator"],
    ["أدوات هندسية", "ruler"],
    ["أساتيك وبرايات", "eraser"],
    ["شنط مدرسية", "backpack"],
    ["حقائب", "backpack"],
    ["ألعاب", "gamepad-2"],
    ["ألعاب تعليمية", "shapes"],
    ["بازل", "puzzle"],
    ["المرحلة الإعدادية", "graduation-cap"],
    ["رياضيات", "sigma"],
    ["ورق تصوير", "file-text"],
    ["هدايا", "gift"],
  ] as const)("%s → %s", (name, icon) => {
    expect(iconFromName(name)).toBe(icon);
  });

  it("returns null when no keyword matches", () => {
    expect(iconFromName("متنوعات")).toBeNull();
  });
});

describe("iconFor", () => {
  const base = { name: "متنوعات", kind: "general", icon: null } as const;

  it("D73 keeps the icon the merchant picked over any guess", () => {
    expect(iconFor({ ...base, name: "أقلام", icon: "gift" })).toBe("gift");
  });

  it("guesses from the name first, then from the subcategories", () => {
    expect(iconFor({ ...base, name: "كراسات", children: [{ name: "أقلام" }] })).toBe("notebook-pen");
    expect(iconFor({ ...base, children: [{ name: "متفرقات" }, { name: "مساطر" }] })).toBe("ruler");
  });

  it.each([
    ["books", "book-open"],
    ["stationery", "pencil"],
    ["toys", "puzzle"],
    ["general", "package"],
  ] as const)("falls back to the %s section's icon", (kind, icon) => {
    expect(iconFor({ ...base, kind })).toBe(icon);
  });
});

it("every pickable icon has an Arabic name and appears once", () => {
  expect(new Set(CATEGORY_ICONS).size).toBe(CATEGORY_ICONS.length);
  expect(Object.keys(ar.catalog.icons).sort()).toEqual([...CATEGORY_ICONS].sort());
});
