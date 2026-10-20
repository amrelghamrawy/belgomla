import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CheckoutSuccess } from "@/components/store/CheckoutSuccess";
import { OrderDetailView } from "@/components/store/OrderDetailView";
import { OrdersList } from "@/components/store/OrdersList";

import { customer, order, page } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { params, renderWithQuery, searchParams } from "../render";

beforeEach(() => {
  params.value = { number: "1001" };
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

function stubOrder(detail = order(), routes: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    "GET /api/store/me": json(200, { data: customer() }),
    "GET /api/store/orders/1001": json(200, { data: detail }),
    ...routes,
  });
}

describe("OrderDetailView", () => {
  it("D6 shows the status, its history, the items, the payment state and the delivery address", async () => {
    stubOrder(
      order({
        status: "confirmed",
        status_label: "مؤكد",
        collection_status: "partially_collected",
        collected_amount: "200.00",
        history: [
          { from: null, to: "pending", at: "2026-10-10T08:00:00Z", by: "customer" },
          { from: "pending", to: "confirmed", at: "2026-10-10T09:00:00Z", by: "tenant_user" },
        ],
      }),
    );
    renderWithQuery(<OrderDetailView />);

    expect(await screen.findByRole("heading", { level: 1, name: "طلب رقم 1001" })).toBeInTheDocument();
    expect(screen.getByTestId("order-status")).toHaveTextContent("مؤكد");
    const timeline = screen.getAllByRole("listitem").filter((item) => item.closest("ol"));
    expect(timeline.map((item) => item.textContent)).toEqual([
      expect.stringContaining("تم إرسال الطلب"),
      expect.stringContaining("مؤكد"),
    ]);
    expect(timeline[1]).toHaveTextContent("بواسطة المتجر");
    expect(screen.getByText("6 كرتونة × 90.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("دفع جزئي · المدفوع 200.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("12 شارع عباس العقاد، مدينة نصر، القاهرة")).toBeInTheDocument();
  });

  it("D85 shows a discounted line's crossed list price and the order discount in the totals", async () => {
    const detail = order();
    stubOrder(
      order({
        items_total: "486.00",
        discount: { type: "amount", value: "6.00", amount: "6.00" },
        order_total: "480.00",
        net_total: "480.00",
        items: detail.items.map((item) => ({
          ...item,
          manual_discount: { type: "percent", value: "10.00" },
          unit_price: "81.00",
          line_total: "486.00",
        })),
      }),
    );
    renderWithQuery(<OrderDetailView />);

    expect(await screen.findByTestId("list-price")).toHaveTextContent("90.00 ج.م");
    expect(screen.getByText(/6 كرتونة ×/)).toHaveTextContent("81.00 ج.م");
    expect(screen.getByTestId("order-discount")).toHaveTextContent("−6.00 ج.م");
    expect(screen.getByText("إجمالي الأصناف").nextSibling).toHaveTextContent("486.00 ج.م");
  });

  it("BR-O12 BR-O13 marks the lines the store removed, changed or delivered short", async () => {
    const [line] = order().items;
    const at = "2026-10-20T08:00:00Z";
    stubOrder(
      order({
        status: "delivered",
        status_label: "تم التوصيل",
        items: [
          { ...line!, id: 61, quantity: 6, delivered_quantity: 4, line_total: "360.00" },
          { ...line!, id: 62, sku_id: 502, product_name: "كتاب ب", delivered_quantity: 6 },
          { ...line!, id: 63, sku_id: 503, product_name: "كتاب ج", removed_at: at },
        ],
        changes: [
          {
            order_item_id: 62,
            product_name: "كتاب ب",
            sku_code: "B-1",
            change: "added",
            old_quantity: null,
            new_quantity: 6,
            unit_price_before: null,
            unit_price_after: "90.00",
            at,
          },
          {
            order_item_id: 63,
            product_name: "كتاب ج",
            sku_code: "C-1",
            change: "removed",
            old_quantity: 6,
            new_quantity: null,
            unit_price_before: "90.00",
            unit_price_after: null,
            at,
          },
        ],
      }),
    );
    renderWithQuery(<OrderDetailView />);

    expect(await screen.findByTestId("delivered-61")).toHaveTextContent("تم تسليم 4 من 6 كرتونة");
    expect(screen.getByTestId("order-line-62")).toHaveTextContent("عدّله المتجر");
    expect(screen.getByTestId("order-line-63")).toHaveTextContent("حذفه المتجر");
    expect(screen.getByTestId("order-line-63")).toHaveClass("line-through");
    expect(screen.getByRole("heading", { name: /الأصناف/ })).toHaveTextContent("(2 صنف)");
  });

  it("an order without discounts shows no crossed price and no discount line", async () => {
    stubOrder();
    renderWithQuery(<OrderDetailView />);

    await screen.findByRole("heading", { level: 1, name: "طلب رقم 1001" });
    expect(screen.queryByTestId("list-price")).not.toBeInTheDocument();
    expect(screen.queryByTestId("order-discount")).not.toBeInTheDocument();
  });

  it("BR-R05 shows the returns of a delivered order and the net total", async () => {
    stubOrder(
      order({
        status: "delivered",
        status_label: "تم التوصيل",
        returned_total: "180.00",
        net_total: "360.00",
        returns: [
          {
            number: 1,
            total: "180.00",
            refund_amount: "50.00",
            refund_method: "cash",
            returned_at: "2026-10-12T08:00:00Z",
            items: [
              {
                product_name: "الأضواء علوم",
                sku_code: "BK-001",
                unit: "كرتونة",
                quantity: 2,
                unit_price: "90.00",
                line_total: "180.00",
              },
            ],
          },
        ],
      }),
    );
    renderWithQuery(<OrderDetailView />);

    const returns = await screen.findByRole("region", { name: "المرتجعات" });
    expect(within(returns).getByText(/مرتجع #1/)).toBeInTheDocument();
    expect(within(returns).getByText("الأضواء علوم · 2 كرتونة × 90.00 ج.م")).toBeInTheDocument();
    expect(within(returns).getByText("تم رد 50.00 ج.م لك")).toBeInTheDocument();
    expect(screen.getByTestId("order-net-total")).toHaveTextContent("360.00 ج.م");
  });

  it("an order without returns shows no returns block", async () => {
    stubOrder();
    renderWithQuery(<OrderDetailView />);

    await screen.findByRole("heading", { level: 1 });

    expect(screen.queryByRole("region", { name: "المرتجعات" })).not.toBeInTheDocument();
  });

  it("BR-O02 a confirmed order cannot be cancelled by the customer", async () => {
    stubOrder(order({ status: "confirmed", status_label: "مؤكد" }));
    renderWithQuery(<OrderDetailView />);

    await screen.findByRole("heading", { level: 1 });

    expect(screen.queryByRole("button", { name: "إلغاء الطلب" })).not.toBeInTheDocument();
  });

  it("BR-O02 a pending order is cancelled with a reason", async () => {
    const cancelled = order({
      status: "cancelled",
      status_label: "ملغي",
      cancel_reason: "طلبت بالخطأ",
      cancelled_by: "customer",
    });
    const fetchMock = stubOrder(order(), { "POST /api/store/orders/1001/cancel": json(200, { data: cancelled }) });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetailView />);

    await user.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    const dialog = await screen.findByRole("dialog", { name: "إلغاء الطلب" });
    await user.type(within(dialog).getByLabelText("سبب الإلغاء"), "طلبت بالخطأ");
    await user.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    await waitFor(() => expect(screen.getByTestId("order-status")).toHaveTextContent("ملغي"));
    expect(screen.getByText("سبب الإلغاء: طلبت بالخطأ")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إلغاء الطلب" })).not.toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/store/orders/1001/cancel")[0]?.body).toEqual({ reason: "طلبت بالخطأ" });
  });

  it("BR-O02 the reason is required", async () => {
    const fetchMock = stubOrder();
    const user = userEvent.setup();
    renderWithQuery(<OrderDetailView />);

    await user.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(within(screen.getByRole("dialog")).getByRole("alert")).toHaveTextContent("هذا الحقل مطلوب");
    expect(callsTo(fetchMock, "POST /api/store/orders/1001/cancel")).toHaveLength(0);
  });

  it("BR-O02 shows why the server refused (the merchant confirmed it meanwhile)", async () => {
    stubOrder(order(), {
      "POST /api/store/orders/1001/cancel": envelopeError(409, "invalid_transition", "لا يمكن إلغاء الطلب بعد تأكيده"),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetailView />);

    await user.click(await screen.findByRole("button", { name: "إلغاء الطلب" }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("سبب الإلغاء"), "x");
    await user.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await within(dialog).findByRole("alert")).toHaveTextContent("لا يمكن إلغاء الطلب بعد تأكيده");
  });

  it("D57 another customer's order is not found", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/orders/1001": envelopeError(404, "not_found", "العنصر غير موجود"),
    });
    renderWithQuery(<OrderDetailView />);

    expect(await screen.findByRole("heading", { name: "الصفحة غير موجودة" })).toBeInTheDocument();
  });
});

describe("OrdersList", () => {
  it("lists the orders newest first with their status, and filters by status", async () => {
    const fetchMock = stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/orders": json(
        200,
        page([order(), order({ number: 1000, status: "delivered", status_label: "تم التوصيل" })]),
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrdersList />);

    const first = await screen.findByRole("link", { name: /طلب رقم 1001/ });
    expect(first).toHaveAttribute("href", "/account/orders/1001");
    expect(first).toHaveTextContent("قيد الانتظار");
    await user.click(screen.getByRole("button", { name: "ملغي" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "GET /api/store/orders").at(-1)?.url.searchParams.get("status")).toBe("cancelled"),
    );
    expect(screen.getByRole("button", { name: "ملغي" })).toHaveAttribute("aria-pressed", "true");
  });

  it("says when there are no orders", async () => {
    stubApi({ "GET /api/store/me": json(200, { data: customer() }), "GET /api/store/orders": json(200, page([])) });
    renderWithQuery(<OrdersList />);

    expect(await screen.findByText("لا توجد طلبات بعد")).toBeInTheDocument();
  });
});

describe("CheckoutSuccess", () => {
  it("shows the order number and links to the order", () => {
    searchParams.value = new URLSearchParams("order=1001");
    renderWithQuery(<CheckoutSuccess />);

    expect(screen.getByTestId("order-number")).toHaveTextContent("1001");
    expect(screen.getByRole("link", { name: "عرض الطلب" })).toHaveAttribute("href", "/account/orders/1001");
  });

  it("does without a number it cannot read", () => {
    searchParams.value = new URLSearchParams("order=abc");
    renderWithQuery(<CheckoutSuccess />);

    expect(screen.queryByTestId("order-number")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "متابعة التسوق" })).toBeInTheDocument();
  });
});
