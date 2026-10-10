import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CollectionsPage } from "@/components/dashboard/collections/CollectionsPage";
import { OrderDetail } from "@/components/dashboard/orders/OrderDetailPage";
import { OrdersPage } from "@/components/dashboard/orders/OrdersPage";
import type { Collection, DashboardOrderDetail } from "@/lib/api/types";

import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

function collection(overrides: Partial<Collection> = {}): Collection {
  return {
    id: 31,
    order: {
      id: 1,
      number: 1001,
      order_total: "450.00",
      collected_amount: "100.00",
      collection_status: "partially_collected",
    },
    customer: { id: 7, name: "مكتبة الأمل" },
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

function order(overrides: Partial<DashboardOrderDetail> = {}): DashboardOrderDetail {
  return {
    id: 1,
    number: 1001,
    status: "confirmed",
    status_label: "مؤكد",
    placed_at: "2026-10-02T08:00:00Z",
    items_total: "450.00",
    order_total: "450.00",
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
    items: [
      {
        sku_id: 5,
        product_name: "الأضواء علوم",
        sku_code: "ADW-SCI-1",
        sku_name: "الأضواء علوم",
        unit: "كرتونة",
        conversion_factor: 20,
        quantity: 5,
        cover_price: "120.00",
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

  it("offers nothing but collecting on a delivered order with money left, and says a settled one is final", () => {
    stubApi({});
    const { rerender } = renderWithQuery(
      <OrderDetail order={order({ status: "delivered", collection_status: "unpaid" })} />,
    );

    expect(
      within(screen.getByTestId("order-actions"))
        .getAllByRole("button")
        .map((button) => button.textContent),
    ).toEqual(["تسجيل تحصيل"]);

    rerender(
      <OrderDetail
        order={order({ status: "delivered", collected_amount: "450.00", collection_status: "collected" })}
      />,
    );

    expect(screen.getByText("هذا الطلب في حالته النهائية.")).toBeInTheDocument();
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

  it("shows the timeline with who moved the order and their note", () => {
    stubApi({});
    renderWithQuery(<OrderDetail order={order()} />);

    expect(screen.getByText("تم إنشاء الطلب")).toBeInTheDocument();
    expect(screen.getByText("تم الاتصال")).toBeInTheDocument();
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
});

describe("CollectionsPage", () => {
  it("lists collections with their order and marks voided ones", async () => {
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
  });
});
