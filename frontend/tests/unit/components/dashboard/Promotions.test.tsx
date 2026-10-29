import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { NewPromotionButton } from "@/components/dashboard/pricing/PromotionDialog";
import { PromotionsPage } from "@/components/dashboard/pricing/PromotionsPage";
import type { Promotion } from "@/lib/api/types";

import { signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { pathname, renderWithQuery as renderBare, router, searchParams } from "../../render";

// Promotions on the dashboard (V2 Task 12, BR-PR15, D90).

function renderWithQuery(ui: ReactElement) {
  return renderBare(ui, signedInAs("owner"));
}

function promotion(overrides: Partial<Promotion> = {}): Promotion {
  return {
    id: 5,
    name: "عرض الأضواء",
    discount_percent: "15.00",
    starts_at: "2026-10-31T22:00:00+00:00",
    ends_at: "2026-11-30T21:59:00+00:00",
    status: "scheduled",
    target: "filter",
    filter: { category: null, f: {}, q: "الأضواء", status: null },
    skus_count: 12,
    cancelled_at: null,
    created_at: "2026-10-27T10:00:00+00:00",
    ...overrides,
  };
}

beforeEach(() => {
  searchParams.value = new URLSearchParams();
  pathname.value = "/";
  router.replace.mockReset();
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
});

describe("PromotionsPage", () => {
  it("BR-PR15 lists each promotion with its %, Cairo window, sku count and status, and the actions it allows", async () => {
    stubApi({
      "GET /api/dashboard/promotions": json(
        200,
        page([
          promotion(),
          promotion({ id: 6, name: "جارٍ", status: "active" }),
          promotion({ id: 7, status: "ended" }),
        ]),
      ),
    });
    renderWithQuery(<PromotionsPage />);

    const scheduled = await screen.findByTestId("promotion-5");
    expect(scheduled).toHaveTextContent("عرض الأضواء");
    expect(scheduled).toHaveTextContent("15%");
    expect(scheduled).toHaveTextContent("1 نوفمبر 2026");
    expect(scheduled).toHaveTextContent("12 صنف");
    expect(scheduled).toHaveTextContent("مجدول");
    expect(within(scheduled).getByRole("button", { name: "تعديل" })).toBeInTheDocument();
    expect(within(screen.getByTestId("promotion-6")).queryByRole("button", { name: "تعديل" })).toBeNull();
    expect(within(screen.getByTestId("promotion-6")).getByRole("button", { name: "إلغاء العرض" })).toBeInTheDocument();
    expect(within(screen.getByTestId("promotion-7")).queryByRole("button")).toBeNull();
  });

  it("filters by status through the URL", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/promotions": json(200, page([])) });
    searchParams.value = new URLSearchParams("status=draft");
    pathname.value = "/dashboard/promotions";
    const user = userEvent.setup();
    renderWithQuery(<PromotionsPage />);

    expect(await screen.findByText("لا توجد عروض بعد")).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/dashboard/promotions")[0]?.url.search).toBe("?status=draft");

    await user.selectOptions(screen.getByLabelText("الحالة"), "active");

    expect(router.replace).toHaveBeenCalledWith("/dashboard/promotions?status=active", { scroll: false });
  });

  it("D90 f schedules a draft", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/promotions": json(200, page([promotion({ status: "draft" })])),
      "PATCH /api/dashboard/promotions/5": json(200, { data: promotion() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<PromotionsPage />);

    await user.click(await screen.findByRole("button", { name: "جدولة" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تمت جدولة العرض"));
    expect(callsTo(fetchMock, "PATCH /api/dashboard/promotions/5")[0]?.body).toEqual({ status: "scheduled" });
  });

  it("D90 f cancels after confirming, and shows the server's refusal", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/promotions": json(200, page([promotion({ status: "active" })])),
      "POST /api/dashboard/promotions/5/cancel": [
        envelopeError(409, "promotion_locked", "لا يمكن تغيير عرض حالته «انتهى»"),
        json(200, { data: promotion({ status: "cancelled" }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<PromotionsPage />);

    await user.click(await screen.findByRole("button", { name: "إلغاء العرض" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "إلغاء العرض" }));
    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("لا يمكن تغيير عرض حالته «انتهى»"));

    await user.click(within(dialog).getByRole("button", { name: "إلغاء العرض" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم إلغاء العرض"));
    expect(callsTo(fetchMock, "POST /api/dashboard/promotions/5/cancel")).toHaveLength(2);
  });

  it("D60 edits a promotion in Cairo time and sends the window as Cairo wall time", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/promotions": json(200, page([promotion()])),
      "PATCH /api/dashboard/promotions/5": json(200, { data: promotion({ discount_percent: "20.00" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<PromotionsPage />);

    await user.click(await screen.findByRole("button", { name: "تعديل" }));
    expect(screen.getByLabelText("يبدأ (بتوقيت القاهرة)")).toHaveValue("2026-11-01T00:00");
    const percent = screen.getByLabelText("نسبة الخصم من السعر الأساسي (%)");
    await user.clear(percent);
    await user.type(percent, "20");
    await user.click(screen.getByRole("button", { name: "حفظ العرض" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم حفظ العرض"));
    expect(callsTo(fetchMock, "PATCH /api/dashboard/promotions/5")[0]?.body).toEqual({
      name: "عرض الأضواء",
      discount_percent: "20",
      starts_at: "2026-11-01T00:00",
      ends_at: "2026-11-30T23:59",
    });
  });
});

describe("NewPromotionButton", () => {
  async function fillForm(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: "عرض ترويجي" }));
    await user.type(screen.getByLabelText("اسم العرض"), "عرض الأضواء");
    await user.type(screen.getByLabelText("نسبة الخصم من السعر الأساسي (%)"), "15");
    await user.type(screen.getByLabelText("يبدأ (بتوقيت القاهرة)"), "2026-11-01T00:00");
    await user.type(screen.getByLabelText("ينتهي (بتوقيت القاهرة)"), "2026-11-30T23:59");
  }

  it("D90 d previews the products the filter selects, then creates the promotion on that filter", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/promotions/preview": json(200, {
        data: {
          skus_count: 12,
          sample: [
            { sku_id: 1, code: "ADW-1", product_name: "الأضواء علوم", base_price: "100.00", promotion_price: "85.00" },
          ],
        },
      }),
      "POST /api/dashboard/promotions": json(201, { data: promotion() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewPromotionButton filter={{ q: "الأضواء" }} />);

    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "معاينة" }));
    expect(await screen.findByTestId("promotion-preview")).toHaveTextContent("سيشمل العرض 12 صنف");
    expect(screen.getByTestId("promotion-preview")).toHaveTextContent("100.00 ج.م ← 85.00 ج.م");
    await user.click(screen.getByLabelText("حفظ كمسودة (لا يطبق حتى تجدوله)"));
    await user.click(screen.getByRole("button", { name: "حفظ العرض" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم حفظ العرض"));
    expect(callsTo(fetchMock, "POST /api/dashboard/promotions/preview")[0]?.body).toEqual({
      filter: { q: "الأضواء" },
      discount_percent: "15",
    });
    const created = callsTo(fetchMock, "POST /api/dashboard/promotions")[0];
    expect(created?.body).toEqual({
      name: "عرض الأضواء",
      discount_percent: "15",
      starts_at: "2026-11-01T00:00",
      ends_at: "2026-11-30T23:59",
      filter: { q: "الأضواء" },
      status: "draft",
    });
    expect(created?.headers["Idempotency-Key"]).toMatch(/^promotion-/);
  });

  it("shows a validation error the server returns next to its field", async () => {
    stubApi({
      "POST /api/dashboard/promotions": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        ends_at: ["يجب أن ينتهي العرض بعد بدايته وبعد الآن"],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewPromotionButton filter={{ q: "الأضواء" }} />);

    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "حفظ العرض" }));

    expect(await screen.findByText("يجب أن ينتهي العرض بعد بدايته وبعد الآن")).toBeInTheDocument();
  });

  it("refuses a % outside 0–90 before sending", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<NewPromotionButton filter={{ q: "الأضواء" }} />);

    await user.click(screen.getByRole("button", { name: "عرض ترويجي" }));
    await user.type(screen.getByLabelText("اسم العرض"), "عرض");
    await user.type(screen.getByLabelText("نسبة الخصم من السعر الأساسي (%)"), "95");
    await user.click(screen.getByRole("button", { name: "حفظ العرض" }));

    expect(await screen.findByText("أدخل نسبة أكبر من 0 وأقل من 90")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/promotions")).toHaveLength(0);
  });
});
