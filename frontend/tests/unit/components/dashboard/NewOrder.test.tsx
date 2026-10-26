import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { describe, expect, it } from "vitest";

import { NewOrderPage } from "@/components/dashboard/orders/NewOrderPage";
import type { DashboardCustomer, OrderQuote } from "@/lib/api/types";

import { dashboardProduct, dashboardSku, orderQuote, orderQuoteLine, signedInAs } from "../../dashboard-fixtures";
import { address, page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery as renderBare, router, searchParams } from "../../render";

/** BR-O11: the merchant's order form, for a signed-in owner unless a test picks a role (D82, D84). */
function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

function customer(overrides: Partial<DashboardCustomer> = {}): DashboardCustomer {
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
    addresses: [
      address({ id: 21, label: "المخزن", is_default: false }),
      address({ id: 22, label: "المحل", is_default: true }),
    ],
    ...overrides,
  };
}

const product = dashboardProduct({
  id: 3,
  name: "الأضواء علوم",
  sku: dashboardSku({ id: 3, code: "ADW-SCI", moq: 5 }),
});

/** The form for customer 7 (from `?customer=7`) with the routes a test needs on top. */
function stubForm(quote: OrderQuote = orderQuote(), routes = {}) {
  searchParams.value = new URLSearchParams("customer=7");

  return stubApi({
    "GET /api/dashboard/customers/7": json(200, { data: customer() }),
    "GET /api/dashboard/products": json(200, page([product])),
    "POST /api/dashboard/orders/quote": json(200, { data: quote }),
    ...routes,
  });
}

async function pickProduct(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: "إضافة صنف" }));
  await user.click(await screen.findByRole("option", { name: /الأضواء علوم/ }));
}

describe("NewOrderPage", () => {
  it("BR-O11 quotes the lines live and places the order for the customer, then opens it", async () => {
    const fetchMock = stubForm(orderQuote(), {
      "POST /api/dashboard/orders": json(201, { data: { number: 1042 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    expect(await screen.findByLabelText("عنوان التوصيل")).toHaveValue("22");
    await pickProduct(user);
    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "12");

    const row = await screen.findByTestId("line-3");
    await waitFor(() => expect(within(row).getAllByText("1,020.00 ج.م").length).toBeGreaterThan(0));
    expect(row).toHaveTextContent("المتاح 20 كرتونة");
    expect(row).toHaveTextContent("من 10: 85.00 ج.م");
    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/orders/quote").at(-1)?.body).toEqual({
        customer_id: 7,
        lines: [{ sku_id: 3, quantity: 12 }],
      }),
    );
    await user.type(screen.getByLabelText("ملاحظة على الطلب (يراها العميل)"), " بالتليفون ");
    await waitFor(() => expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "تسجيل الطلب" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/dashboard/orders/1042"));
    const placed = callsTo(fetchMock, "POST /api/dashboard/orders")[0];
    expect(placed?.body).toEqual({
      customer_id: 7,
      address_id: 22,
      lines: [{ sku_id: 3, quantity: 12 }],
      note: "بالتليفون",
      notify_customer: true,
      override_mov: false,
    });
    expect(placed?.headers["Idempotency-Key"]).toMatch(/^merchant-order-/);
  });

  it("BR-O11 the customer message can be turned off", async () => {
    const fetchMock = stubForm(orderQuote(), {
      "POST /api/dashboard/orders": json(201, { data: { number: 1042 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);
    await user.click(screen.getByRole("switch", { name: "إرسال رسالة للعميل" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "تسجيل الطلب" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/orders")[0]?.body).toMatchObject({ notify_customer: false }),
    );
  });

  it("BR-PR06 shows the quote's problem on its line and does not let the order go", async () => {
    stubForm(
      orderQuote({
        lines: [orderQuoteLine({ quantity: 5, problem: { reason: "insufficient_stock", requested: 5, available: 3 } })],
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);

    expect(await screen.findByTestId("problem-3")).toHaveTextContent("المتاح 3 كرتونة فقط");
    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();
  });

  it("D84 the owner may waive the minimum order value, and only then may the order go", async () => {
    const fetchMock = stubForm(orderQuote({ mov: { enabled: true, amount: "1500.00", satisfied: false } }), {
      "POST /api/dashboard/orders": json(201, { data: { number: 1042 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);

    expect(await screen.findByText(/الحد الأدنى للطلب 1,500.00 ج.م/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();
    await user.click(screen.getByRole("checkbox", { name: "تجاوز الحد الأدنى للطلب" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "تسجيل الطلب" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/orders")[0]?.body).toMatchObject({ override_mov: true }),
    );
  });

  it("D84 sales does not see the override", async () => {
    stubForm(orderQuote({ mov: { enabled: true, amount: "1500.00", satisfied: false } }));
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />, signedInAs("sales"));

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);

    expect(await screen.findByText(/الحد الأدنى للطلب 1,500.00 ج.م/)).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: "تجاوز الحد الأدنى للطلب" })).toBeNull();
    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();
  });

  it("BR-C05 shows the credit limit the order would pass", async () => {
    stubForm(orderQuote({ credit: { limit: "1000.00", exposure: "200.00", order_total: "1020.00" } }));
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);

    expect(await screen.findByText(/يتجاوز حد ائتمان العميل: الحد 1,000.00 ج.م/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();
  });

  it("shows the server's refusal when placing fails", async () => {
    stubForm(orderQuote(), {
      "POST /api/dashboard/orders": envelopeError(
        409,
        "customer_blocked",
        "هذا العميل محظور، ألغِ الحظر أولًا لتسجيل طلب له",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);
    await waitFor(() => expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "تسجيل الطلب" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("هذا العميل محظور");
    expect(router.push).not.toHaveBeenCalled();
  });

  it("removes a line and stops quoting when no line is left", async () => {
    const fetchMock = stubForm();
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);
    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/orders/quote")).toHaveLength(1));
    await user.click(screen.getByRole("button", { name: "حذف الصنف: الأضواء علوم" }));

    expect(screen.getByText("أضف صنفًا واحدًا على الأقل")).toBeInTheDocument();
    expect(screen.getByTestId("quote-total")).toHaveTextContent("0.00");
  });

  it("BR-CU03 adds an address to the customer inline and picks it", async () => {
    const fetchMock = stubForm(orderQuote(), {
      "PUT /api/dashboard/customers/7": json(200, {
        data: customer({
          addresses: [...(customer().addresses ?? []), address({ id: 23, label: "الفرع", is_default: false })],
        }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await user.click(screen.getByRole("button", { name: "إضافة عنوان" }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("اسم العنوان"), "الفرع");
    await user.type(within(dialog).getByLabelText("المحافظة"), "الجيزة");
    await user.type(within(dialog).getByLabelText("المدينة / المنطقة"), "الدقي");
    await user.type(within(dialog).getByLabelText("العنوان بالتفصيل"), "5 شارع التحرير");
    await user.click(within(dialog).getByRole("button", { name: "تأكيد" }));

    await waitFor(() => expect(screen.getByLabelText("عنوان التوصيل")).toHaveValue("23"));
    expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).toMatchObject({
      name: "مكتبة الأمل",
      addresses: [{ id: 21 }, { id: 22 }, { label: "الفرع", city: "الدقي" }],
    });
  });

  it("BR-O11 a customer who is not active cannot be ordered for", async () => {
    stubForm(orderQuote(), {
      "GET /api/dashboard/customers/7": json(200, { data: customer({ status: "blocked", status_label: "محظور" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    expect(await screen.findByText(/لا يمكن تسجيل طلب لهذا العميل الآن/)).toBeInTheDocument();
    await pickProduct(user);
    await screen.findByTestId("line-3");

    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();
  });

  it("starts without a customer and searches active ones only", async () => {
    searchParams.value = new URLSearchParams();
    const fetchMock = stubApi({ "GET /api/dashboard/customers": json(200, page([customer()])) });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    expect(screen.getByRole("combobox", { name: "العميل" })).toHaveTextContent("اختر العميل");
    await user.click(screen.getByRole("combobox", { name: "العميل" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "GET /api/dashboard/customers")[0]?.url.searchParams.get("status")).toBe("active"),
    );
  });
  it("D85 discounts a line and the order on the form, shows the total after them and sends them", async () => {
    const fetchMock = stubForm(orderQuote(), {
      "POST /api/dashboard/orders": json(201, { data: { number: 1042 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />);

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);
    await user.clear(screen.getByLabelText("كمية الأضواء علوم"));
    await user.type(screen.getByLabelText("كمية الأضواء علوم"), "12");
    await waitFor(() =>
      expect(within(screen.getByTestId("line-3")).getAllByText("1,020.00 ج.م").length).toBeGreaterThan(0),
    );

    await user.type(screen.getByLabelText("خصم الأضواء علوم"), "10");
    await user.type(screen.getByLabelText("خصم خصم على إجمالي الطلب"), "18");
    await user.selectOptions(screen.getByLabelText("نوع الخصم: خصم على إجمالي الطلب"), "amount");

    expect(screen.getByTestId("line-3")).toHaveTextContent("76.50 ج.م");
    expect(screen.getByTestId("discounted-total")).toHaveTextContent("900.00 ج.م");
    expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeDisabled();

    await user.type(screen.getByLabelText("سبب خصم الطلب"), "طلب بالتليفون");
    await waitFor(() => expect(screen.getByRole("button", { name: "تسجيل الطلب" })).toBeEnabled());
    await user.click(screen.getByRole("button", { name: "تسجيل الطلب" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/orders")[0]?.body).toMatchObject({
        lines: [{ sku_id: 3, quantity: 12, discount: { type: "percent", value: "10" } }],
        discount: { type: "amount", value: "18", reason: "طلب بالتليفون" },
      }),
    );
  });

  it("D85 a role without orders.discount gets no discount controls", async () => {
    stubForm();
    const user = userEvent.setup();
    renderWithQuery(<NewOrderPage />, signedInAs("warehouse"));

    await screen.findByLabelText("عنوان التوصيل");
    await pickProduct(user);

    expect(screen.queryByLabelText("خصم الأضواء علوم")).not.toBeInTheDocument();
    expect(screen.queryByTestId("new-order-discounts")).not.toBeInTheDocument();
  });
});
