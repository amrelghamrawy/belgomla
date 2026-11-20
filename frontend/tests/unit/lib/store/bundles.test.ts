import { describe, expect, it } from "vitest";

import { bundleUnavailableMessage, isBundle, missingComponents, savingLabel } from "@/lib/store/bundles";

import { bundleProduct, storeProduct } from "../../fixtures";

describe("storefront bundles (BR-P15, D93)", () => {
  it("tells a bundle from a simple product", () => {
    expect(isBundle(bundleProduct())).toBe(true);
    expect(isBundle(storeProduct())).toBe(false);
  });

  it("says what the bundle saves only when it saves something", () => {
    expect(savingLabel(bundleProduct())).toBe("وفّر 15.00 ج.م");
    expect(savingLabel(bundleProduct({ saving: "0.00" }))).toBeNull();
    expect(savingLabel(bundleProduct({ saving: null }))).toBeNull();
    expect(savingLabel(bundleProduct({ saving: undefined }))).toBeNull();
    expect(savingLabel(storeProduct({ saving: "5.00" }))).toBeNull();
  });

  it("names the component that is missing, with its variant", () => {
    expect(bundleUnavailableMessage(bundleProduct())).toBe("الباقة غير متاحة الآن: ينقص سلاح التلميذ رياضيات");

    const [book, math] = bundleProduct().components!;
    const pen = { ...book!, product_name: "قلم جاف", label: "أزرق", in_stock: false };
    expect(missingComponents({ components: [book!, math!, pen] })).toEqual(["سلاح التلميذ رياضيات", "قلم جاف (أزرق)"]);
  });

  it("says nothing for a bundle that can be made or for a simple product", () => {
    expect(bundleUnavailableMessage(bundleProduct({ availability: 2 }))).toBeNull();
    expect(bundleUnavailableMessage(storeProduct())).toBeNull();
  });

  it("still says the bundle is unavailable when no component is named", () => {
    const components = bundleProduct().components!.map((component) => ({ ...component, in_stock: true }));

    expect(bundleUnavailableMessage(bundleProduct({ components }))).toBe("الباقة غير متاحة الآن");
  });
});
