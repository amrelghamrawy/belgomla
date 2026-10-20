import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CollectionsPage } from "@/components/dashboard/collections/CollectionsPage";
import { OrderDetail } from "@/components/dashboard/orders/OrderDetailPage";
import { OrdersPage } from "@/components/dashboard/orders/OrdersPage";
import type { Collection, DashboardCustomer, DashboardOrderDetail, OrderReturn } from "@/lib/api/types";

import { signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery as renderBare, router, searchParams } from "../../render";

/** These screens hide what the role may not do (D82): render them for a signed-in owner unless a test picks a role. */
function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

function collection(overrides: Partial<Collection> = {}): Collection {
  return {
    id: 31,
    order: {
      id: 1,
      number: 1001,
      order_total: "450.00",
      net_total: "450.00",
      collected_amount: "100.00",
      collection_status: "partially_collected",
    },
    customer: { id: 7, name: "مكتبة الأمل" },
    kind: "payment",
    kind_label: "تحصيل",
    order_return_id: null,
    customer_credit_id: null,
    amount: "100.00",
    method: "cash",
    method_label: "نقدي",
    collected_at: "2026-10-03T08:00:00Z",
    recorded_by: 1,
    note: null,
    is_voided: false,
    voided_at: null,
    void_reason: null,
    voided_by: null,
    created_at: "2026-10-03T08:00:00Z",
    ...overrides,
  };
}

function orderReturn(overrides: Partial<OrderReturn> = {}): OrderReturn {
  return {
    id: 41,
    number: 1,
    total: "180.00",
    refund_amount: "90.00",
    refund_method: "cash",
    reason: "مرتجع نهاية الموسم",
    returned_at: "2026-10-04T08:00:00Z",
    recorded_by: 1,
    is_voided: false,
    voided_at: null,
    void_reason: null,
    voided_by: null,
    items: [
      {
        id: 51,
        order_item_id: 61,
        product_name: "الأضواء علوم",
        sku_code: "ADW-SCI-1",
        unit: "كرتونة",
        quantity: 2,
        quantity_base: 40,
        unit_price: "90.00",
        line_total: "180.00",
      },
    ],
    created_at: "2026-10-04T08:00:00Z",
    ...overrides,
  };
}

function order(overrides: Partial<DashboardOrderDetail> = {}): DashboardOrderDetail {
  return {
    messages: [],
    id: 1,
    number: 1001,
    status: "confirmed",
    status_label: "مؤكد",
    placed_at: "2026-10-02T08:00:00Z",
    items_total: "450.00",
    discount: null,
    order_total: "450.00",
    returned_total: "0.00",
    net_total: "450.00",
    collection_status: "unpaid",
    collected_amount: "0.00",
    delivery: {
      name: "مكتبة الأمل",
      phone: "+201012345678",
      address: {
        label: "المحل",
        governorate: "القاهرة",
        city: "مدينة نصر",
        address_line: "شارع عباس العقاد",
        landmark: null,
      },
    },
    customer_note: null,
    cancel_reason: null,
    cancelled_by: null,
    customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
    confirmed_at: "2026-10-02T09:00:00Z",
    delivered_at: null,
    cancelled_at: null,
    is_stale: false,
    expires_at: null,
    placed_by: "customer",
    placed_by_id: 9,
    items: [
      {
        id: 61,
        sku_id: 5,
        product_name: "الأضواء علوم",
        sku_code: "ADW-SCI-1",
        sku_name: "الأضواء علوم",
        unit: "كرتونة",
        conversion_factor: 20,
        quantity: 5,
        cover_price: "120.00",
        list_unit_price: "90.00",
        manual_discount: null,
        unit_price: "90.00",
        discount_percent: "25.00",
        tier_min_quantity: null,
        line_total: "450.00",
      },
    ],
    history: [
      { from: null, to: "pending", at: "2026-10-02T08:00:00Z", by: "customer", actor_id: 7, note: null },
      {
        from: "pending",
        to: "confirmed",
        at: "2026-10-02T09:00:00Z",
        by: "tenant_user",
        actor_id: 1,
        note: "تم الاتصال",
      },
    ],
    collections: [],
    returns: [],
    ...overrides,
  };
}

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("OrderDetail actions", () => {
  const buttons = () =>
    within(screen.getByTestId("order-actions"))
      .getAllByRole("button")
      .map((button) => button.textContent);

  it("D82 the warehouse prepares and sends out, but neither delivers, collects nor cancels", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ status: "preparing" })} />, signedInAs("warehouse"));

    expect(buttons()).toEqual(["خرج للتوصيل"]);
  });

  it("D82 the accountant only collects", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ status: "preparing" })} />, signedInAs("accountant"));

    expect(buttons()).toEqual(["تسجيل تحصيل"]);
  });

  it("D82 sales moves and cancels the order but does not collect", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ status: "preparing" })} />, signedInAs("sales"));

    expect(buttons()).toEqual(["خرج للتوصيل", "تم التسليم", "إلغاء الطلب"]);
  });

  it("BR-O01 shows only the forward steps the status allows, plus cancel", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ status: "preparing" })} />);

    const actions = screen.getByTestId("order-actions");
    expect(
      within(actions)
        .getAllByRole("button")
        .map((button) => button.textContent),
    ).toEqual(["خرج للتوصيل", "تم التسليم", "تسليم وتحصيل كامل", "تسجيل تحصيل", "إلغاء الطلب"]);
  });

  it("BR-R01 offers collecting and a return on a delivered order, only the return once settled, nothing once cancelled", () => {
    stubApi({});
    const actions = () =>
      within(screen.getByTestId("order-actions"))
        .getAllByRole("button")
        .map((button) => button.textContent);
    const { rerender } = renderWithQuery(
      <OrderDetail order={order({ status: "delivered", collection_status: "unpaid" })} />,
    );

    expect(actions()).toEqual(["تسجيل تحصيل", "تسجيل مرتجع"]);

    rerender(
      <OrderDetail
        order={order({ status: "delivered", collected_amount: "450.00", collection_status: "collected" })}
      />,
    );
    expect(actions()).toEqual(["تسجيل مرتجع"]);

    rerender(
      <OrderDetail order={order({ status: "cancelled", cancel_reason: "نفاد", cancelled_by: "tenant_user" })} />,
    );
    expect(screen.getByText("هذا الطلب في حالته النهائية.")).toBeInTheDocument();
  });

  it("BR-R05 shows the returned and net totals and the balance on the net total", () => {
    stubApi({});
    renderWithQuery(
      <OrderDetail
        order={order({ status: "delivered", returned_total: "90.00", net_total: "360.00", collected_amount: "100.00" })}
      />,
    );

    expect(screen.getByTestId("order-returned")).toHaveTextContent("90.00 ج.م");
    expect(screen.getByTestId("order-balance")).toHaveTextContent("260.00 ج.م");
  });

  it("BR-R06 lists the returns, voided struck out, and voids a live one with a reason", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/returns/41/void": json(200, { data: orderReturn({ is_voided: true }) }),
      "GET /api/dashboard/orders/1001": json(200, { data: order() }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <OrderDetail
        order={order({
          status: "delivered",
          returns: [
            orderReturn(),
            orderReturn({
              id: 42,
              number: 2,
              refund_amount: "0.00",
              is_voided: true,
              voided_at: "2026-10-05T08:00:00Z",
              void_reason: "مكرر",
            }),
          ],
        })}
      />,
    );

    const list = screen.getByTestId("order-returns");
    expect(within(list).getByText("سبب الإلغاء: مكرر")).toBeInTheDocument();
    expect(within(list).getByText("رُد للعميل 90.00 ج.م")).toBeInTheDocument();
    await user.click(within(list).getByRole("button", { name: "إلغاء المرتجع" }));
    await user.type(screen.getByLabelText("السبب"), "سجلناه بالخطأ");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إلغاء المرتجع" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم إلغاء المرتجع"));
    const call = callsTo(fetchMock, "POST /api/dashboard/returns/41/void")[0];
    expect(call?.body).toEqual({ reason: "سجلناه بالخطأ" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^void-return-/);
  });

  it("BR-R04 shows a refund collection with a minus sign and a refund badge", () => {
    stubApi({});
    renderWithQuery(
      <OrderDetail
        order={order({ collections: [collection({ kind: "refund", kind_label: "رد", order_return_id: 41 })] })}
      />,
    );

    const line = within(screen.getByTestId("order-collections")).getByText(/−100.00 ج.م/);
    expect(line).toHaveTextContent("رد");
  });

  it("D55 moves the order forward with an Idempotency-Key and shows the new status", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/1001/transition": json(200, {
        data: order({ status: "preparing", status_label: "قيد التجهيز" }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order()} />);

    await user.click(screen.getByRole("button", { name: "بدء التجهيز" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("أصبحت حالة الطلب: قيد التجهيز"));
    const call = callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")[0];
    expect(call?.body).toEqual({ to: "preparing" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^transition-1001-[0-9a-f]{32}$/);
  });

  it("BR-C04 delivers and collects the full balance in one step", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/1001/transition": json(200, {
        data: order({ status: "delivered", collected_amount: "450.00", collection_status: "collected" }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "out_for_delivery" })} />);

    await user.click(screen.getByRole("button", { name: "تسليم وتحصيل كامل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم التسليم وتحصيل المبلغ كاملًا"));
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")[0]?.body).toEqual({
      to: "delivered",
      collect_full_amount: true,
    });
  });

  it("BR-O03 shows why an order with collections cannot be cancelled", async () => {
    stubApi({
      "POST /api/dashboard/orders/1001/cancel": envelopeError(
        409,
        "has_collections",
        "لا يمكن إلغاء طلب عليه تحصيلات، ألغِ التحصيلات أولًا",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <OrderDetail order={order({ collected_amount: "100.00", collection_status: "partially_collected" })} />,
    );

    await user.click(screen.getByRole("button", { name: "إلغاء الطلب" }));
    await user.type(screen.getByLabelText("السبب"), "العميل اعتذر");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إلغاء الطلب" }));

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent(
      "لا يمكن إلغاء طلب عليه تحصيلات",
    );
  });

  it("records a collection for what is left and shows exceeds_balance", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/1001/collections": envelopeError(
        422,
        "exceeds_balance",
        "المبلغ أكبر من المتبقي على الطلب",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <OrderDetail order={order({ collected_amount: "100.00", collection_status: "partially_collected" })} />,
    );

    await user.click(screen.getByRole("button", { name: "تسجيل تحصيل" }));
    expect(screen.getByLabelText("المبلغ")).toHaveValue("350.00");
    await user.clear(screen.getByLabelText("المبلغ"));
    await user.type(screen.getByLabelText("المبلغ"), "400");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل تحصيل" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("المبلغ أكبر من المتبقي على الطلب");
    const call = callsTo(fetchMock, "POST /api/dashboard/orders/1001/collections")[0];
    expect(call?.body).toEqual({ amount: "400", note: null });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^collection-1001-/);
  });

  it("voids a collection with a reason", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/collections/31/void": json(200, { data: collection({ is_voided: true }) }),
      "GET /api/dashboard/orders/1001": json(200, { data: order() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ collections: [collection()], collected_amount: "100.00" })} />);

    await user.click(within(screen.getByTestId("order-collections")).getByRole("button", { name: "إلغاء التحصيل" }));
    await user.type(screen.getByLabelText("السبب"), "خطأ في المبلغ");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إلغاء التحصيل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم إلغاء التحصيل"));
    expect(callsTo(fetchMock, "POST /api/dashboard/collections/31/void")[0]?.body).toEqual({ reason: "خطأ في المبلغ" });
  });
});

describe("OrderDetail discounts (BR-PR08, D85)", () => {
  const discounted = (): DashboardOrderDetail =>
    order({
      items_total: "405.00",
      discount: { type: "amount", value: "5.00", amount: "5.00", reason: "عميل دائم" },
      order_total: "400.00",
      net_total: "400.00",
      items: [
        {
          id: 61,
          sku_id: 5,
          product_name: "الأضواء علوم",
          sku_code: "ADW-SCI-1",
          sku_name: "الأضواء علوم",
          unit: "كرتونة",
          conversion_factor: 20,
          quantity: 5,
          cover_price: "120.00",
          list_unit_price: "90.00",
          manual_discount: { type: "percent", value: "10.00" },
          unit_price: "81.00",
          discount_percent: "25.00",
          tier_min_quantity: null,
          line_total: "405.00",
        },
      ],
    });

  it("offers a discount on a pending or confirmed order to who may discount, not once it is being prepared", () => {
    stubApi({});
    const { rerender } = renderWithQuery(<OrderDetail order={order({ status: "pending" })} />);

    expect(screen.getByRole("button", { name: "خصم" })).toBeInTheDocument();

    rerender(<OrderDetail order={order({ status: "preparing" })} />);
    expect(screen.queryByRole("button", { name: "خصم" })).not.toBeInTheDocument();
  });

  it("D82 the warehouse and the accountant cannot discount", () => {
    stubApi({});
    const { unmount } = renderWithQuery(<OrderDetail order={order()} />, signedInAs("accountant"));

    expect(screen.queryByRole("button", { name: "خصم" })).not.toBeInTheDocument();
    unmount();

    renderWithQuery(<OrderDetail order={order()} />, signedInAs("warehouse"));
    expect(screen.queryByRole("button", { name: "خصم" })).not.toBeInTheDocument();
  });

  it("shows the crossed list price of a discounted line and the order discount with its reason", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={discounted()} />);

    expect(screen.getByLabelText("قبل الخصم")).toHaveTextContent("90.00 ج.م");
    expect(screen.getByTestId("order-discount")).toHaveTextContent("−5.00 ج.م");
    expect(screen.getByTestId("order-discount")).toHaveTextContent("عميل دائم");
  });

  it("shows the totals live and saves the line and order discounts with an Idempotency-Key", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/orders/1001/discount": json(200, { data: discounted() }) });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order()} />);

    await user.click(screen.getByRole("button", { name: "خصم" }));
    await user.type(screen.getByLabelText("خصم الأضواء علوم"), "10");
    await user.type(screen.getByLabelText("خصم خصم على إجمالي الطلب"), "5");
    await user.selectOptions(screen.getByLabelText("نوع الخصم: خصم على إجمالي الطلب"), "amount");

    expect(screen.getByTestId("discount-order-total")).toHaveTextContent("400.00 ج.م");

    await user.click(screen.getByRole("button", { name: "حفظ الخصم" }));
    expect(screen.getByRole("alert")).toHaveTextContent("اكتب سبب الخصم على الطلب");

    await user.type(screen.getByLabelText("سبب خصم الطلب"), "عميل دائم");
    await user.click(screen.getByRole("button", { name: "حفظ الخصم" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم حفظ الخصم"));
    const call = callsTo(fetchMock, "PUT /api/dashboard/orders/1001/discount")[0];
    expect(call?.body).toEqual({
      items: [{ order_item_id: 61, type: "percent", value: "10" }],
      order: { type: "amount", value: "5", reason: "عميل دائم" },
    });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^discount-1001-[0-9a-f]{32}$/);
  });

  it("starts from the order's discounts and removes them all when cleared", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/orders/1001/discount": json(200, { data: order() }) });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={discounted()} />);

    await user.click(screen.getByRole("button", { name: "خصم" }));
    expect(screen.getByLabelText("خصم الأضواء علوم")).toHaveValue("10.00");

    await user.clear(screen.getByLabelText("خصم الأضواء علوم"));
    await user.clear(screen.getByLabelText("خصم خصم على إجمالي الطلب"));
    await user.click(screen.getByRole("button", { name: "حفظ الخصم" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/orders/1001/discount")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/orders/1001/discount")[0]?.body).toEqual({ items: [], order: null });
  });

  it("refuses a discount above the price before sending, and shows the server's refusal", async () => {
    const fetchMock = stubApi({
      "PUT /api/dashboard/orders/1001/discount": envelopeError(403, "discount_over_limit", "غير مسموح لك بإعطاء خصم"),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order()} />, signedInAs("sales"));

    await user.click(screen.getByRole("button", { name: "خصم" }));
    await user.selectOptions(screen.getByLabelText("نوع الخصم: الأضواء علوم"), "amount");
    await user.type(screen.getByLabelText("خصم الأضواء علوم"), "95");
    await user.click(screen.getByRole("button", { name: "حفظ الخصم" }));

    expect(screen.getByRole("alert")).toHaveTextContent("خصم غير صالح أو أكبر من السعر");
    expect(callsTo(fetchMock, "PUT /api/dashboard/orders/1001/discount")).toHaveLength(0);

    await user.clear(screen.getByLabelText("خصم الأضواء علوم"));
    await user.type(screen.getByLabelText("خصم الأضواء علوم"), "1");
    await user.click(screen.getByRole("button", { name: "حفظ الخصم" }));

    await waitFor(() => expect(screen.getByRole("alert")).toHaveTextContent("غير مسموح لك بإعطاء خصم"));
  });
});

describe("OrderDetail delivery address", () => {
  it("D9 edits the address before out for delivery and locks it after", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/orders/1001/delivery-address": json(200, { data: order() }) });
    const user = userEvent.setup();
    const { rerender } = renderWithQuery(<OrderDetail order={order()} />);

    await user.click(screen.getByRole("button", { name: "تعديل العنوان" }));
    await user.clear(screen.getByLabelText("المدينة / المنطقة"));
    await user.type(screen.getByLabelText("المدينة / المنطقة"), "المعادي");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/orders/1001/delivery-address")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/orders/1001/delivery-address")[0]?.body).toMatchObject({
      city: "المعادي",
      governorate: "القاهرة",
      phone: null,
    });

    rerender(<OrderDetail order={order({ status: "out_for_delivery" })} />);

    expect(screen.queryByRole("button", { name: "تعديل العنوان" })).not.toBeInTheDocument();
    expect(screen.getByTestId("delivery")).toHaveTextContent("لا يمكن تعديل العنوان بعد خروج الطلب للتوصيل.");
  });

  it("BR-O11 marks an order the merchant placed", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ placed_by: "tenant_user", placed_by_id: 1 })} />);

    expect(screen.getByTestId("placed-by-merchant")).toHaveTextContent("أنشأه التاجر");
  });

  it("shows the timeline with who moved the order and their note", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order()} />);

    expect(screen.getByText("تم إنشاء الطلب")).toBeInTheDocument();
    expect(screen.getByText("تم الاتصال")).toBeInTheDocument();
  });

  it("BR-N03 lists the messages the customer was sent, and no section when there are none", () => {
    stubApi({});
    const { unmount } = renderWithQuery(
      <OrderDetail
        order={order({
          messages: [
            {
              purpose: "order_status",
              purpose_label: "تحديث طلب",
              channel: "whatsapp",
              status: "fell_back",
              sent_at: "2026-10-03T09:00:00Z",
            },
            {
              purpose: "order_status",
              purpose_label: "تحديث طلب",
              channel: "whatsapp",
              status: "delivered",
              sent_at: "2026-10-03T09:00:05Z",
            },
          ],
        })}
      />,
    );

    const messages = within(screen.getByTestId("order-messages"));
    expect(screen.getByText("الرسائل")).toBeInTheDocument();
    expect(messages.getAllByRole("listitem")).toHaveLength(2);
    expect(messages.getAllByText("تحديث طلب · واتساب")).toHaveLength(2);
    expect(messages.getByText(/أُعيد إرسالها من رقم آخر/)).toBeInTheDocument();
    expect(messages.getByText(/وصلت/)).toBeInTheDocument();

    unmount();
    renderWithQuery(<OrderDetail order={order()} />);
    expect(screen.queryByText("الرسائل")).not.toBeInTheDocument();
  });
});

describe("OrdersPage", () => {
  it("lists orders with the stale badge and filters by status tab through the URL", async () => {
    stubApi({
      "GET /api/dashboard/orders": json(
        200,
        page([
          {
            ...order({ status: "pending", is_stale: true }),
            items: undefined,
            history: undefined,
            collections: undefined,
          },
        ]),
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrdersPage />);

    const row = await screen.findByTestId("order-1001");
    expect(row).toHaveTextContent("متأخر");
    expect(within(row).getByRole("link", { name: "#1001" })).toHaveAttribute("href", "/dashboard/orders/1001");
    await user.click(screen.getByRole("button", { name: "خرج للتوصيل" }));

    expect(router.replace).toHaveBeenCalledWith("/?status=out_for_delivery", { scroll: false });
  });

  it("BR-O11 offers a new order to who may create orders and marks the orders the merchant placed", async () => {
    stubApi({
      "GET /api/dashboard/orders": json(
        200,
        page([
          {
            ...order({ placed_by: "tenant_user", placed_by_id: 1 }),
            items: undefined,
            history: undefined,
            collections: undefined,
          },
          { ...order({ number: 1002 }), items: undefined, history: undefined, collections: undefined },
        ]),
      ),
    });
    renderWithQuery(<OrdersPage />);

    expect(screen.getByRole("link", { name: "طلب جديد" })).toHaveAttribute("href", "/dashboard/orders/new");
    expect(within(await screen.findByTestId("order-1001")).getByTestId("placed-by-merchant")).toHaveTextContent(
      "أنشأه التاجر",
    );
    expect(within(screen.getByTestId("order-1002")).queryByTestId("placed-by-merchant")).toBeNull();
  });

  it("D82 the warehouse gets no new-order button", async () => {
    stubApi({ "GET /api/dashboard/orders": json(200, page([])) });
    renderWithQuery(<OrdersPage />, signedInAs("warehouse"));

    expect(await screen.findByText("لا توجد طلبات")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "طلب جديد" })).toBeNull();
  });

  it("D81 shows how long a pending order has before it cancels itself", async () => {
    const inFiveAndAHalfHours = new Date(Date.now() + 330 * 60_000 - 1_000).toISOString();
    stubApi({
      "GET /api/dashboard/orders": json(
        200,
        page([
          {
            ...order({ status: "pending", expires_at: inFiveAndAHalfHours }),
            items: undefined,
            history: undefined,
            collections: undefined,
          },
          {
            ...order({ number: 1002, status: "pending" }),
            items: undefined,
            history: undefined,
            collections: undefined,
          },
        ]),
      ),
    });
    renderWithQuery(<OrdersPage />);

    expect(within(await screen.findByTestId("order-1001")).getByTestId("expiry")).toHaveTextContent(
      "يُلغى بعد 5 س 30 د",
    );
    expect(within(screen.getByTestId("order-1002")).queryByTestId("expiry")).toBeNull();
  });

  it("filters by a customer searched by name or phone, resetting the page", async () => {
    searchParams.value = new URLSearchParams("page=3");
    const fetchMock = stubApi({
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/customers": json(200, page([pickerCustomer()])),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrdersPage />);

    await user.click(screen.getByRole("combobox", { name: "العميل" }));
    await user.type(screen.getByPlaceholderText("ابحث بالاسم أو رقم الهاتف"), "الأمل");
    await waitFor(() =>
      expect(callsTo(fetchMock, "GET /api/dashboard/customers").at(-1)?.url.searchParams.get("q")).toBe("الأمل"),
    );
    await user.click(await screen.findByRole("option", { name: /مكتبة الأمل/ }));

    expect(router.replace).toHaveBeenCalledWith("/?customer_id=7", { scroll: false });
  });

  it("names the filtered customer from the URL and clears the filter", async () => {
    searchParams.value = new URLSearchParams("customer_id=7&status=pending");
    const fetchMock = stubApi({
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/customers/7": json(200, { data: pickerCustomer() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrdersPage />);

    await waitFor(() => expect(screen.getByRole("combobox", { name: "العميل" })).toHaveTextContent("مكتبة الأمل"));
    expect(callsTo(fetchMock, "GET /api/dashboard/orders")[0]?.url.searchParams.get("customer_id")).toBe("7");
    await user.click(screen.getByRole("button", { name: "إلغاء تصفية العميل" }));

    expect(router.replace).toHaveBeenCalledWith("/?status=pending", { scroll: false });
  });

  it("does not load customers until the picker opens", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/orders": json(200, page([])) });
    renderWithQuery(<OrdersPage />);

    expect(await screen.findByText("لا توجد طلبات")).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/dashboard/customers")).toHaveLength(0);
    expect(screen.getByRole("combobox", { name: "العميل" })).toHaveTextContent("كل العملاء");
  });
});

function pickerCustomer(): DashboardCustomer {
  return {
    id: 7,
    name: "مكتبة الأمل",
    phone: "+201012345678",
    order_notifications: true,
    status: "active",
    status_label: "نشط",
    phone_verified_at: "2026-10-01T08:00:00Z",
    created_at: "2026-10-01T08:00:00Z",
    outstanding_amount: "0.00",
    created_by: null,
    approved_by: null,
    blocked_at: null,
    blocked_reason: null,
    credit_limit: null,
    payment_terms_days: null,
  };
}

describe("CollectionsPage", () => {
  it("lists collections with their order and marks voided ones with the reason on the row", async () => {
    stubApi({
      "GET /api/dashboard/collections": json(
        200,
        page([collection(), collection({ id: 32, is_voided: true, void_reason: "خطأ" })]),
      ),
    });

    renderWithQuery(<CollectionsPage />);

    const row = await screen.findByTestId("collection-31");
    expect(within(row).getByRole("link", { name: "#1001" })).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(within(screen.getByTestId("collection-32")).getByText("ملغي")).toBeInTheDocument();
    expect(within(screen.getByTestId("collection-32")).getByTestId("void-reason")).toHaveTextContent("خطأ");
  });
});
