import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CollectionsPage } from "@/components/dashboard/collections/CollectionsPage";
import { OrderDetail } from "@/components/dashboard/orders/OrderDetailPage";
import { OrdersPage } from "@/components/dashboard/orders/OrdersPage";
import type { Collection, DashboardCustomer, DashboardOrderDetail, OrderReturn } from "@/lib/api/types";

import { orderQuote, orderQuoteLine, signedInAs } from "../../dashboard-fixtures";
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
    kind: "order",
    preorder_state: null,
    converted_at: null,
    converted_from: null,
    conversions: [],
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
        sku_unit_id: 5,
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
        price_source: "base",
        line_total: "450.00",
        delivered_quantity: null,
        removed_at: null,
        converted_quantity: 0,
      },
    ],
    changes: [],
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
    await user.click(await screen.findByRole("button", { name: "تسليم وتحصيل" }));

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

describe("OrderDetail price source (BR-PR14)", () => {
  it("names a line priced at the customer's own price, not a base one", () => {
    stubApi({});
    const base = order();
    const line = base.items[0];
    const items =
      line === undefined
        ? []
        : [line, { ...line, id: 62, sku_id: 6, sku_unit_id: 6, price_source: "customer" as const }];
    renderWithQuery(<OrderDetail order={{ ...base, items }} />);

    expect(screen.getByTestId("price-source-62")).toHaveTextContent("سعر خاص بالعميل");
    expect(screen.queryByTestId("price-source-61")).toBeNull();
  });

  it("BR-PR15 names a line priced by a promotion", () => {
    stubApi({});
    const base = order();
    const line = base.items[0];
    const items = line === undefined ? [] : [{ ...line, id: 63, price_source: "promotion" as const }];
    renderWithQuery(<OrderDetail order={{ ...base, items }} />);

    expect(screen.getByTestId("price-source-63")).toHaveTextContent("سعر العرض");
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
          sku_unit_id: 5,
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
          price_source: "base",
          line_total: "405.00",
          delivered_quantity: null,
          removed_at: null,
          converted_quantity: 0,
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

describe("OrderDetail line editing (BR-O12, D87)", () => {
  const twoLines = (): DashboardOrderDetail => {
    const base = order({ status: "pending" });
    const [line] = base.items;

    return {
      ...base,
      items_total: "1300.00",
      order_total: "1300.00",
      net_total: "1300.00",
      items: [
        line!,
        {
          ...line!,
          id: 62,
          sku_id: 6,
          sku_unit_id: 6,
          product_name: "كتاب ب",
          sku_code: "B-1",
          quantity: 10,
          unit_price: "85.00",
          list_unit_price: "85.00",
          line_total: "850.00",
        },
      ],
    };
  };
  const quoteFor = (quantity: number) =>
    orderQuote({
      lines: [
        orderQuoteLine({
          sku_id: 5,
          sku_unit_id: 5,
          product_name: "الأضواء علوم",
          quantity,
          unit_price: "85.00",
          line_total: `${85 * quantity}.00`,
        }),
      ],
    });

  it("offers the edit on a pending or confirmed order to who may edit, not to the warehouse nor once prepared", () => {
    stubApi({});
    const { rerender, unmount } = renderWithQuery(<OrderDetail order={order({ status: "confirmed" })} />);

    expect(screen.getByRole("button", { name: "تعديل الأصناف" })).toBeInTheDocument();
    rerender(<OrderDetail order={order({ status: "preparing" })} />);
    expect(screen.queryByRole("button", { name: "تعديل الأصناف" })).not.toBeInTheDocument();
    unmount();

    renderWithQuery(<OrderDetail order={order({ status: "pending" })} />, signedInAs("warehouse"));
    expect(screen.queryByRole("button", { name: "تعديل الأصناف" })).not.toBeInTheDocument();
  });

  it("shows the diff before saving and saves the whole list with an Idempotency-Key", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/quote": json(200, { data: quoteFor(12) }),
      "PUT /api/dashboard/orders/1001/lines": json(200, {
        data: order({ status: "pending" }),
        meta: { below_mov: null },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={twoLines()} />);

    await user.click(screen.getByRole("button", { name: "تعديل الأصناف" }));
    expect(screen.getByTestId("edit-changes")).toHaveTextContent("لم تغيّر شيئًا بعد");
    expect(screen.getByRole("button", { name: "حفظ التعديل" })).toBeDisabled();

    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "12");
    await user.click(screen.getByRole("button", { name: "حذف الصنف: كتاب ب" }));

    const changes = screen.getByTestId("edit-changes");
    expect(changes).toHaveTextContent("حذف كتاب ب (10)");
    expect(changes).toHaveTextContent("الأضواء علوم: من 5 إلى 12");
    await waitFor(() => expect(screen.getByTestId("edit-total")).toHaveTextContent("1,020.00 ج.م"));
    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/orders/quote").at(-1)?.body).toEqual({
        customer_id: 7,
        order: 1001,
        lines: [{ sku_unit_id: 5, quantity: 12 }],
      }),
    );

    await user.type(screen.getByLabelText("ملاحظة للسجل (اختياري)"), "العميل اتصل");
    await waitFor(() => expect(screen.getByRole("button", { name: "حفظ التعديل" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "حفظ التعديل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تعديل أصناف الطلب"));
    const call = callsTo(fetchMock, "PUT /api/dashboard/orders/1001/lines")[0];
    expect(call?.body).toEqual({ lines: [{ order_item_id: 61, sku_unit_id: 5, quantity: 12 }], note: "العميل اتصل" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^lines-1001-[0-9a-f]{32}$/);
  });

  it("BR-MOV02 warns when the edited order went below the minimum order value", async () => {
    vi.spyOn(toast, "warning").mockImplementation(() => "t");
    stubApi({
      "POST /api/dashboard/orders/quote": json(200, { data: quoteFor(12) }),
      "PUT /api/dashboard/orders/1001/lines": json(200, {
        data: order({ status: "pending" }),
        meta: { below_mov: { required: "2000.00", current: "1020.00" } },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={twoLines()} />);

    await user.click(screen.getByRole("button", { name: "تعديل الأصناف" }));
    await user.click(screen.getByRole("button", { name: "حذف الصنف: كتاب ب" }));
    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "12");
    await waitFor(() => expect(screen.getByRole("button", { name: "حفظ التعديل" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "حفظ التعديل" }));

    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        "تم الحفظ، لكن إجمالي الطلب 1,020.00 ج.م أقل من الحد الأدنى للطلب 2,000.00 ج.م",
      ),
    );
  });

  it("BR-PR06 a problem of a changed line blocks saving, and the server's refusal is shown", async () => {
    stubApi({
      "POST /api/dashboard/orders/quote": json(200, {
        data: orderQuote({
          lines: [
            orderQuoteLine({
              sku_id: 5,
              sku_unit_id: 5,
              quantity: 30,
              problem: { reason: "insufficient_stock", requested: 30, available: 20 },
            }),
          ],
        }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "pending" })} />);

    await user.click(screen.getByRole("button", { name: "تعديل الأصناف" }));
    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "30");

    expect(await screen.findByTestId("problem-5")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "حفظ التعديل" })).toBeDisabled();
  });

  it("shows the server's refusal", async () => {
    stubApi({
      "POST /api/dashboard/orders/quote": json(200, { data: quoteFor(6) }),
      "PUT /api/dashboard/orders/1001/lines": envelopeError(
        409,
        "order_locked",
        "لا يمكن تعديل أصناف طلب حالته «قيد التجهيز»",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "pending" })} />);

    await user.click(screen.getByRole("button", { name: "تعديل الأصناف" }));
    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "6");
    await waitFor(() => expect(screen.getByRole("button", { name: "حفظ التعديل" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "حفظ التعديل" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("لا يمكن تعديل أصناف طلب حالته «قيد التجهيز»");
  });

  it("marks removed, changed and short lines and lists the changes", () => {
    stubApi({});
    const base = order({ status: "delivered" });
    const [line] = base.items;
    const at = "2026-10-20T08:00:00Z";
    renderWithQuery(
      <OrderDetail
        order={{
          ...base,
          items: [
            { ...line!, delivered_quantity: 3, line_total: "270.00" },
            { ...line!, id: 62, sku_id: 6, sku_unit_id: 6, product_name: "كتاب ب", removed_at: at },
          ],
          changes: [
            {
              order_item_id: 61,
              product_name: "الأضواء علوم",
              sku_code: "ADW-SCI-1",
              change: "quantity",
              old_quantity: 4,
              new_quantity: 5,
              unit_price_before: "90.00",
              unit_price_after: "90.00",
              at,
              actor_id: 1,
            },
            {
              order_item_id: 62,
              product_name: "كتاب ب",
              sku_code: "B-1",
              change: "removed",
              old_quantity: 2,
              new_quantity: null,
              unit_price_before: "90.00",
              unit_price_after: null,
              at,
              actor_id: 1,
            },
            {
              order_item_id: 61,
              product_name: "الأضواء علوم",
              sku_code: "ADW-SCI-1",
              change: "delivered_short",
              old_quantity: 5,
              new_quantity: 3,
              unit_price_before: "90.00",
              unit_price_after: "90.00",
              at,
              actor_id: 1,
            },
          ],
        }}
      />,
    );

    expect(screen.getByTestId("order-line-61")).toHaveTextContent("معدَّل");
    expect(screen.getByTestId("delivered-61")).toHaveTextContent("سُلِّم 3 من 5");
    expect(screen.getByTestId("order-line-62")).toHaveTextContent("محذوف");
    const changes = screen.getByTestId("order-changes");
    expect(changes).toHaveTextContent("الأضواء علوم: من 4 إلى 5");
    expect(changes).toHaveTextContent("حُذف كتاب ب (2)");
    expect(changes).toHaveTextContent("الأضواء علوم: سُلِّم 3 من 5");
  });
});

describe("OrderDetail delivery (BR-O13, D86)", () => {
  it("delivers in full after the confirmation, without delivered quantities", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/1001/transition": json(200, { data: order({ status: "delivered" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "out_for_delivery" })} />);

    await user.click(screen.getByRole("button", { name: "تم التسليم" }));
    await user.click(await screen.findByRole("button", { name: "تأكيد التسليم" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("أصبحت حالة الطلب: تم التوصيل"));
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")[0]?.body).toEqual({ to: "delivered" });
  });

  it("records a partial delivery: the lines are collapsed until asked, then sent with what went out", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/orders/1001/transition": json(200, { data: order({ status: "delivered" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "out_for_delivery" })} />);

    await user.click(screen.getByRole("button", { name: "تم التسليم" }));
    expect(screen.queryByTestId("delivered-lines")).not.toBeInTheDocument();

    await user.click(screen.getByLabelText("تسليم جزئي؟"));
    const stepper = screen.getByLabelText("الكمية المسلَّمة من الأضواء علوم");
    expect(stepper).toHaveValue(5);

    await user.clear(stepper);
    await user.type(stepper, "3");
    expect(screen.getByTestId("delivered-total")).toHaveTextContent("270.00 ج.م");

    await user.click(screen.getByRole("button", { name: "تأكيد التسليم" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")).toHaveLength(1));
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")[0]?.body).toEqual({
      to: "delivered",
      delivered_quantities: [{ order_item_id: 61, quantity: 3 }],
    });
  });

  it("D86 refuses to deliver nothing at all: the order is cancelled instead", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<OrderDetail order={order({ status: "out_for_delivery" })} />);

    await user.click(screen.getByRole("button", { name: "تم التسليم" }));
    await user.click(screen.getByLabelText("تسليم جزئي؟"));
    await user.clear(screen.getByLabelText("الكمية المسلَّمة من الأضواء علوم"));

    expect(screen.getByText("لم يتم تسليم أي صنف، ألغِ الطلب بدلًا من ذلك")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تأكيد التسليم" })).toBeDisabled();
    expect(callsTo(fetchMock, "POST /api/dashboard/orders/1001/transition")).toHaveLength(0);
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
    group: null,
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

describe("pre-orders on the dashboard (BR-O14, D88)", () => {
  const preorder = (overrides: Partial<DashboardOrderDetail> = {}) =>
    order({ kind: "preorder", status: "pending", status_label: "قيد الانتظار", preorder_state: "open", ...overrides });

  it("lists pre-orders with their own badge and keeps one kind through the URL", async () => {
    stubApi({
      "GET /api/dashboard/orders": json(
        200,
        page([{ ...preorder(), items: undefined, history: undefined, collections: undefined }]),
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrdersPage />);

    const row = await screen.findByTestId("order-1001");
    expect(within(row).getByTestId("preorder-badge")).toHaveTextContent("حجز مسبق · محجوز");
    await user.click(screen.getByRole("button", { name: /تصفية/ }));
    await user.selectOptions(screen.getByLabelText("النوع"), "preorder");

    expect(router.replace).toHaveBeenCalledWith("/?kind=preorder", { scroll: false });
  });

  it("an open pre-order is only cancelled: no steps, discount, line edits or collections", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={preorder()} />);

    expect(screen.getByRole("heading", { name: "حجز مسبق #1001" })).toBeInTheDocument();
    expect(
      within(screen.getByTestId("order-actions"))
        .getAllByRole("button")
        .map((button) => button.textContent),
    ).toEqual(["إلغاء الطلب"]);
    expect(screen.queryByTestId("collection-status")).not.toBeInTheDocument();
  });

  it("a converted pre-order has no actions and links to its orders; its lines say what was converted", () => {
    stubApi({});
    const converted = preorder({
      preorder_state: "converted",
      converted_at: "2026-10-12T08:00:00Z",
      items: [{ ...order().items[0]!, converted_quantity: 5 }],
      conversions: [{ number: 1005, status: "pending", order_total: "450.00", placed_at: "2026-10-12T08:00:00Z" }],
      changes: [
        {
          order_item_id: 61,
          product_name: "الأضواء علوم",
          sku_code: "ADW-SCI-1",
          change: "converted",
          old_quantity: 5,
          new_quantity: 0,
          unit_price_before: "90.00",
          unit_price_after: "95.00",
          at: "2026-10-12T08:00:00Z",
          actor_id: 1,
        },
      ],
    });
    renderWithQuery(<OrderDetail order={converted} />);

    expect(screen.queryByTestId("order-actions")).not.toBeInTheDocument();
    expect(within(screen.getByTestId("order-conversions")).getByRole("link", { name: "#1005" })).toHaveAttribute(
      "href",
      "/dashboard/orders/1005",
    );
    expect(screen.getByTestId("converted-61")).toHaveTextContent("تحوّل 5 من 5");
    expect(screen.getByText("الأضواء علوم: تحوّل 5 إلى طلب، والمتبقي محجوزًا 0")).toBeInTheDocument();
  });

  it("an order converted from a pre-order links back to it", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order({ converted_from: 990 })} />);

    expect(
      within(screen.getByTestId("order-conversions")).getByRole("link", { name: "من الحجز المسبق #990" }),
    ).toHaveAttribute("href", "/dashboard/orders/990");
  });
});
