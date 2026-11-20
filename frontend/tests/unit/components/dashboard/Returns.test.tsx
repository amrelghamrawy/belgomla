import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ReturnDialog } from "@/components/dashboard/orders/ReturnDialog";
import { ReturnsPage } from "@/components/dashboard/returns/ReturnsPage";
import type { OrderReturn, ReturnableLine } from "@/lib/api/types";
import { returnItems, returnValue } from "@/lib/dashboard/returns";

import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

function line(overrides: Partial<ReturnableLine> = {}): ReturnableLine {
  return {
    order_item_id: 61,
    product_name: "الأضواء علوم",
    sku_code: "ADW-SCI-1",
    unit: "كرتونة",
    unit_price: "90.00",
    ordered: 6,
    delivered: 6,
    returned: 4,
    returnable: 2,
    ...overrides,
  };
}

function orderReturn(overrides: Partial<OrderReturn> = {}): OrderReturn {
  return {
    id: 41,
    number: 1,
    order: {
      id: 1,
      number: 1001,
      order_total: "540.00",
      returned_total: "180.00",
      net_total: "360.00",
      collected_amount: "0.00",
      collection_status: "unpaid",
    },
    customer: { id: 7, name: "مكتبة الأمل" },
    total: "180.00",
    refund_amount: "0.00",
    refund_method: null,
    reason: "مرتجع نهاية الموسم",
    returned_at: "2026-10-04T08:00:00Z",
    recorded_by: 1,
    is_voided: false,
    voided_at: null,
    void_reason: null,
    voided_by: null,
    items: [],
    created_at: "2026-10-04T08:00:00Z",
    ...overrides,
  };
}

const returnsOf = (lines: ReturnableLine[]) => json(200, { data: [], returnable: lines });

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("return helpers", () => {
  it("D74 values the chosen quantities at each line's order price", () => {
    expect(returnValue([line(), line({ order_item_id: 62, unit_price: "85.50" })], { 61: 2, 62: 1 })).toBe("265.50");
    expect(returnValue([line()], {})).toBe("0.00");
  });

  it("sends only the lines with a quantity", () => {
    expect(returnItems({ 61: 2, 62: 0 })).toEqual([{ order_item_id: 61, quantity: 2 }]);
  });
});

describe("ReturnDialog", () => {
  it("BR-R02 caps each stepper at what the line can still take back and shows the value", async () => {
    stubApi({ "GET /api/dashboard/orders/1001/returns": returnsOf([line()]) });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    const plus = await screen.findByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" });
    await user.click(plus);
    await user.click(plus);

    expect(plus).toBeDisabled();
    expect(screen.getByLabelText("الكمية المرتجعة الأضواء علوم")).toHaveValue("2");
    expect(screen.getByTestId("return-value")).toHaveTextContent("180.00 ج.م");
    expect(screen.getByText(/المطلوب: 6 كرتونة/)).toHaveTextContent("مرتجع سابقًا: 4");
  });

  it("says so when everything delivered already came back", async () => {
    stubApi({ "GET /api/dashboard/orders/1001/returns": returnsOf([line({ returned: 6, returnable: 0 })]) });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));

    expect(await screen.findByText("تم إرجاع كل الكميات المسلّمة من هذا الطلب.")).toBeInTheDocument();
  });

  it("asks for a quantity and a reason before sending", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/orders/1001/returns": returnsOf([line()]) });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    const dialog = await screen.findByRole("dialog");
    await within(dialog).findByTestId("return-lines");
    await user.click(within(dialog).getByRole("button", { name: "تسجيل مرتجع" }));
    expect(within(dialog).getByRole("alert")).toHaveTextContent("اختر كمية لصنف واحد على الأقل");

    await user.click(within(dialog).getByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" }));
    await user.click(within(dialog).getByRole("button", { name: "تسجيل مرتجع" }));
    expect(within(dialog).getByRole("alert")).toHaveTextContent("اكتب السبب");
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/returns")).toHaveLength(0);
  });

  it("BR-R04 shows the refund the server asks for and sends again once the cash refund is confirmed", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/orders/1001/returns": returnsOf([line()]),
      "POST /api/dashboard/orders/1001/returns": [
        envelopeError(422, "refund_required", "يجب رد 90.00 للعميل", { refund_amount: "90.00" }),
        json(201, { data: orderReturn({ refund_amount: "90.00" }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" }));
    await user.type(screen.getByLabelText("سبب المرتجع"), "نهاية الموسم");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));

    expect(await screen.findByText(/يجب رد 90.00 ج.م للعميل/)).toBeInTheDocument();
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));
    expect(within(screen.getByRole("dialog")).getByText("أكد رد المبلغ للمتابعة")).toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: "تأكيد رد المبلغ" }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تسجيل المرتجع"));
    const calls = callsTo(fetchMock, "POST /api/dashboard/orders/1001/returns");
    expect(calls[0]?.body).toEqual({ reason: "نهاية الموسم", items: [{ order_item_id: 61, quantity: 1 }] });
    expect(calls[1]?.body).toEqual({
      reason: "نهاية الموسم",
      items: [{ order_item_id: 61, quantity: 1 }],
      refund: true,
      refund_to: "cash",
      refund_reference: null,
    });
    expect(calls[1]?.headers["Idempotency-Key"]).toMatch(/^return-1001-/);
  });

  it("BR-C07 keeps the refund as customer credit when the merchant picks it", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/orders/1001/returns": returnsOf([line()]),
      "POST /api/dashboard/orders/1001/returns": [
        envelopeError(422, "refund_required", "يجب رد 90.00", { refund_amount: "90.00" }),
        json(201, { data: orderReturn({ refund_amount: "90.00", refund_method: "credit" }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" }));
    await user.type(screen.getByLabelText("سبب المرتجع"), "نهاية الموسم");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("radio", { name: "رصيد للعميل يُخصم من طلباته القادمة" }));
    await user.click(screen.getByRole("checkbox", { name: "تأكيد رد المبلغ" }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تسجيل المرتجع"));
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/returns")[1]?.body).toMatchObject({
      refund: true,
      refund_to: "credit",
    });
  });

  it("D103 pays the refund back by InstaPay with its reference", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/orders/1001/returns": returnsOf([line()]),
      "POST /api/dashboard/orders/1001/returns": [
        envelopeError(422, "refund_required", "يجب رد 90.00", { refund_amount: "90.00" }),
        json(201, { data: orderReturn({ refund_amount: "90.00", refund_method: "instapay" }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" }));
    await user.type(screen.getByLabelText("سبب المرتجع"), "نهاية الموسم");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("radio", { name: "رد بإنستاباي" }));
    await user.type(screen.getByLabelText("رقم العملية (اختياري)"), "IP-55120");
    await user.click(screen.getByRole("checkbox", { name: "تأكيد رد المبلغ" }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تسجيل المرتجع"));
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/returns")[1]?.body).toMatchObject({
      refund: true,
      refund_to: "instapay",
      refund_reference: "IP-55120",
    });
  });

  it("shows any other refusal under the form", async () => {
    stubApi({
      "GET /api/dashboard/orders/1001/returns": returnsOf([line()]),
      "POST /api/dashboard/orders/1001/returns": envelopeError(
        409,
        "return_not_allowed",
        "المرتجع للطلبات المسلّمة فقط",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));
    await user.click(await screen.findByRole("button", { name: "الكمية المرتجعة + الأضواء علوم" }));
    await user.type(screen.getByLabelText("سبب المرتجع"), "خطأ");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل مرتجع" }));

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent(
      "المرتجع للطلبات المسلّمة فقط",
    );
  });
});

describe("ReturnsPage", () => {
  it("BR-R05 lists returns with their order, customer and value, voided ones struck out", async () => {
    stubApi({
      "GET /api/dashboard/returns": json(
        200,
        page([orderReturn(), orderReturn({ id: 42, number: 2, is_voided: true, void_reason: "مكرر" })]),
      ),
    });
    renderWithQuery(<ReturnsPage />);

    const row = await screen.findByTestId("return-41");
    expect(within(row).getByRole("link", { name: /#1001/ })).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(within(row).getByRole("link", { name: "مكتبة الأمل" })).toHaveAttribute("href", "/dashboard/customers/7");
    expect(within(screen.getByTestId("return-42")).getByText("مكرر")).toBeInTheDocument();
  });

  it("searches by order or customer through the URL and says when nothing matches", async () => {
    stubApi({ "GET /api/dashboard/returns": json(200, page([])) });
    const user = userEvent.setup();
    renderWithQuery(<ReturnsPage />);

    expect(await screen.findByText("لا توجد مرتجعات")).toBeInTheDocument();
    await user.type(within(screen.getByRole("search")).getByRole("textbox", { name: "بحث" }), "1001");
    await user.click(screen.getByRole("button", { name: "تطبيق" }));

    expect(router.replace).toHaveBeenCalledWith(expect.stringContaining("q=1001"), { scroll: false });
  });
});

describe("ReturnDialog bundles (BR-P15, D93)", () => {
  it("names the bundle a component line came in", async () => {
    stubApi({
      "GET /api/dashboard/orders/1001/returns": returnsOf([
        line({ order_item_id: 71 }),
        line({ order_item_id: 73, product_name: "قلم" }),
      ]),
    });
    const user = userEvent.setup();
    renderWithQuery(<ReturnDialog orderNumber={1001} bundles={{ 71: "طقم أولى إعدادي" }} />);

    await user.click(screen.getByRole("button", { name: "تسجيل مرتجع" }));

    expect(await screen.findByTestId("return-bundle-71")).toHaveTextContent("ضمن طقم أولى إعدادي");
    expect(screen.queryByTestId("return-bundle-73")).not.toBeInTheDocument();
  });
});
