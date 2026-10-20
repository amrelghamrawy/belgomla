import { describe, expect, it } from "vitest";

import {
  NOTIFIABLE_STATUSES,
  orderMessagesBody,
  placeholdersFor,
  renderOrderMessage,
  unknownOrderPlaceholders,
} from "@/lib/dashboard/order-status-message";

const values = {
  customer: "محمود",
  store: "مكتبة النور",
  number: "1024",
  status: "مؤكد",
  total: "540.00",
  reason: "نفاد الكمية",
};

describe("order status messages (mirrors App\\Support\\OrderStatusMessage)", () => {
  it("BR-N03 offers the cancel reason in the cancelled text only", () => {
    expect(placeholdersFor("cancelled")).toContain("reason");

    for (const status of NOTIFIABLE_STATUSES.filter((each) => each !== "cancelled")) {
      expect(placeholdersFor(status)).not.toContain("reason");
    }
  });

  it("BR-N03 lists unknown placeholders once, in order, and the reason as unknown outside cancelled", () => {
    expect(unknownOrderPlaceholders("{name} {اسم_العميل} {name} {سبب_الإلغاء}", "confirmed")).toEqual([
      "{name}",
      "{سبب_الإلغاء}",
    ]);
    expect(unknownOrderPlaceholders("{سبب_الإلغاء}", "cancelled")).toEqual([]);
  });

  it("BR-N03 fills every placeholder", () => {
    expect(
      renderOrderMessage(
        "{اسم_العميل}: طلب {رقم_الطلب} {حالة_الطلب} بقيمة {الإجمالي} من {اسم_المتجر} {سبب_الإلغاء}",
        values,
      ),
    ).toBe("محمود: طلب 1024 مؤكد بقيمة 540.00 من مكتبة النور نفاد الكمية");
  });

  it("BR-N03 sends null for an empty box or the default text, the store's words otherwise", () => {
    const defaults = {
      confirmed: "أ",
      preparing: "ب",
      out_for_delivery: "ج",
      delivered: "د",
      cancelled: "هـ",
      discount: "و",
    };

    expect(
      orderMessagesBody({ ...defaults, confirmed: "  أ  ", preparing: " ", delivered: " وصل " }, defaults),
    ).toEqual({
      confirmed: null,
      preparing: null,
      out_for_delivery: null,
      delivered: "وصل",
      cancelled: null,
      discount: null,
    });
  });
});
