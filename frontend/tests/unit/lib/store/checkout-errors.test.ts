import { describe, expect, it } from "vitest";

import { ApiError } from "@/lib/api/errors";
import { checkoutFailure } from "@/lib/store/checkout-errors";

import { cartItem } from "../../fixtures";

const lines = [
  cartItem(),
  cartItem({ sku_id: 502, product_name: "المعلم رياضيات", product_slug: "المعلم-رياضيات", unit: "نسخة" }),
];

function failed(items: Record<string, unknown>[], details: Record<string, unknown> | null = null) {
  return new ApiError(422, "checkout_failed", "تعذر إتمام الطلب", details, items);
}

describe("checkoutFailure", () => {
  it("BR-PR06 names each SKU with its reason and links back to its product", () => {
    const failure = checkoutFailure(
      failed([
        { sku_id: 501, reason: "insufficient_stock", requested: 6, available: 3 },
        { sku_id: 502, reason: "below_moq", moq: 10 },
      ]),
      lines,
    );

    expect(failure).toEqual({
      problems: [
        {
          skuId: 501,
          productName: "الأضواء علوم أولى إعدادي",
          productSlug: "الأضواء-علوم-أولى-إعدادي",
          message: "المتاح 3 كرتونة فقط",
        },
        {
          skuId: 502,
          productName: "المعلم رياضيات",
          productSlug: "المعلم-رياضيات",
          message: "الحد الأدنى للطلب 10 نسخة",
        },
      ],
      mov: null,
    });
  });

  it("BR-P04 explains a product that is no longer active", () => {
    expect(checkoutFailure(failed([{ sku_id: 501, reason: "product_inactive" }]), lines)?.problems[0]?.message).toBe(
      "لم يعد متاحًا للطلب",
    );
  });

  it("BR-MOV01 shows the minimum order value against the cart total", () => {
    expect(checkoutFailure(failed([], { mov: { required: "1000.00", current: "700.00" } }), lines)?.mov).toBe(
      "الحد الأدنى للطلب 1,000.00 ج.م، وإجمالي سلتك 700.00 ج.م",
    );
  });

  it("keeps a problem for a SKU no longer in the cart, without a link", () => {
    expect(checkoutFailure(failed([{ sku_id: 999, reason: "product_inactive" }]), lines)?.problems[0]).toMatchObject({
      productName: null,
      productSlug: null,
    });
  });

  it("reads cart_line_invalid the same way", () => {
    const error = new ApiError(422, "cart_line_invalid", "x", null, [{ sku_id: 501, reason: "below_moq", moq: 5 }]);

    expect(checkoutFailure(error, lines)?.problems).toHaveLength(1);
  });

  it.each([
    ["another error code", new ApiError(422, "cart_empty", "السلة فارغة")],
    ["a non-API error", new Error("boom")],
  ])("is null for %s", (_, error) => {
    expect(checkoutFailure(error, lines)).toBeNull();
  });
});
