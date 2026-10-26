import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerDetail } from "@/components/dashboard/customers/CustomerDetailPage";
import { CustomerGroupsPage } from "@/components/dashboard/customers/CustomerGroupsPage";
import { CustomerPricesTab, negotiatedPrice } from "@/components/dashboard/customers/CustomerPricesTab";
import { CustomersPage } from "@/components/dashboard/customers/CustomersPage";
import type { CustomerGroup, CustomerSkuPrice, DashboardCustomer } from "@/lib/api/types";
import { assignmentFor } from "@/lib/dashboard/customer-pricing";

import { signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery as renderBare, searchParams } from "../../render";

// Customer groups and negotiated prices on the dashboard (V2 Task 11, BR-PR12..PR14, D89).

function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

function group(overrides: Partial<CustomerGroup> = {}): CustomerGroup {
  return {
    id: 3,
    name: "موزع",
    discount_percent: "15.00",
    status: "active",
    sort_order: 0,
    customers_count: 2,
    ...overrides,
  };
}

function customerRow(overrides: Partial<DashboardCustomer> = {}): DashboardCustomer {
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
    addresses: [],
    recent_orders: [],
    ...overrides,
  };
}

function price(overrides: Partial<CustomerSkuPrice> = {}): CustomerSkuPrice {
  return {
    sku_id: 11,
    sku_code: "ADW-1",
    product_name: "الأضواء علوم",
    unit: "كرتونة",
    base_price: "90.00",
    unit_price: "82.50",
    note: "اتفاق الموسم",
    updated_at: "2026-10-20T08:00:00Z",
    ...overrides,
  };
}

beforeEach(() => {
  searchParams.value = new URLSearchParams();
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
});

describe("assignmentFor", () => {
  it("BR-PR12 sends the ticked ids, else the list filter", () => {
    expect(assignmentFor([7, 8], { q: "النور" })).toEqual({ customer_ids: [7, 8] });
    expect(assignmentFor([], { q: "النور", status: "active", group: "3" })).toEqual({
      filter: { q: "النور", status: "active", group: 3 },
    });
    expect(assignmentFor([], {})).toEqual({ filter: {} });
  });
});

describe("negotiatedPrice", () => {
  it("BR-PR13 keeps a positive amount with up to 2 decimals and refuses the rest", () => {
    expect(negotiatedPrice(" 82.5 ")).toBe("82.5");
    expect(negotiatedPrice("0")).toBeNull();
    expect(negotiatedPrice("1.234")).toBeNull();
    expect(negotiatedPrice("x")).toBeNull();
  });
});

describe("CustomerGroupsPage", () => {
  it("BR-PR12 lists the groups with their %, members linked to the filtered customers, and status", async () => {
    stubApi({
      "GET /api/dashboard/customer-groups": json(200, {
        data: [group(), group({ id: 4, name: "قديم", status: "archived", customers_count: 0 })],
      }),
    });
    renderWithQuery(<CustomerGroupsPage />);

    const row = await screen.findByTestId("group-3");
    expect(row).toHaveTextContent("موزع15%");
    expect(within(row).getByRole("link", { name: "2 عميل" })).toHaveAttribute("href", "/dashboard/customers?group=3");
    expect(within(screen.getByTestId("group-4")).getByRole("button", { name: "إعادة تفعيل" })).toBeInTheDocument();
  });

  it("BR-PR12 creates a group from the dialog and shows the server's duplicate name", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customer-groups": json(200, { data: [] }),
      "POST /api/dashboard/customer-groups": [
        envelopeError(409, "duplicate_name", "يوجد مستوى عملاء بنفس الاسم"),
        json(201, { data: group({ name: "مكتبة كبيرة" }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerGroupsPage />);

    await user.click(await screen.findByRole("button", { name: "مستوى جديد" }));
    await user.type(screen.getByLabelText("اسم المستوى"), "مكتبة كبيرة");
    await user.type(screen.getByLabelText("نسبة الخصم من السعر الأساسي (%)"), "7.5");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("يوجد مستوى عملاء بنفس الاسم");

    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم حفظ المستوى"));
    expect(callsTo(fetchMock, "POST /api/dashboard/customer-groups")[1]?.body).toEqual({
      name: "مكتبة كبيرة",
      discount_percent: "7.5",
      sort_order: 0,
    });
  });

  it("D89 refuses a % above 90 before sending", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/customer-groups": json(200, { data: [] }) });
    const user = userEvent.setup();
    renderWithQuery(<CustomerGroupsPage />);

    await user.click(await screen.findByRole("button", { name: "مستوى جديد" }));
    await user.type(screen.getByLabelText("اسم المستوى"), "موزع");
    await user.type(screen.getByLabelText("نسبة الخصم من السعر الأساسي (%)"), "95");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByText("أدخل نسبة من 0 إلى 90")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/customer-groups")).toHaveLength(0);
  });

  it("D89 archives an active group and restores an archived one", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customer-groups": json(200, { data: [group(), group({ id: 4, status: "archived" })] }),
      "POST /api/dashboard/customer-groups/3/archive": json(200, { data: group({ status: "archived" }) }),
      "PATCH /api/dashboard/customer-groups/4": json(200, { data: group({ id: 4 }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerGroupsPage />);

    await user.click(within(await screen.findByTestId("group-3")).getByRole("button", { name: "أرشفة" }));
    await user.click(within(screen.getByTestId("group-4")).getByRole("button", { name: "إعادة تفعيل" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "PATCH /api/dashboard/customer-groups/4")[0]?.body).toEqual({ status: "active" }),
    );
    expect(callsTo(fetchMock, "POST /api/dashboard/customer-groups/3/archive")).toHaveLength(1);
  });
});

describe("CustomersPage groups (BR-PR12)", () => {
  it("shows each customer's group and moves the ticked ones into a group", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customers": json(
        200,
        page([
          customerRow({ group: { id: 3, name: "موزع", discount_percent: "15.00", status: "active" } }),
          customerRow({ id: 8, name: "مكتبة النور" }),
        ]),
      ),
      "GET /api/dashboard/customer-groups": json(200, { data: [group()] }),
      "POST /api/dashboard/customer-groups/3/assign": json(200, { data: { assigned: 1 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomersPage />);

    expect(await screen.findByTestId("customer-7")).toHaveTextContent("موزع");
    await user.click(screen.getByRole("checkbox", { name: "تحديد مكتبة النور" }));
    await user.selectOptions(await screen.findByRole("combobox", { name: "تعيين إلى مستوى" }), "3");
    await user.click(screen.getByRole("button", { name: "تعيين المحددين" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تعيين 1 عميل"));
    expect(callsTo(fetchMock, "POST /api/dashboard/customer-groups/3/assign")[0]?.body).toEqual({ customer_ids: [8] });
  });

  it("moves every customer of the current filter when none is ticked", async () => {
    searchParams.value = new URLSearchParams("q=النور");
    const fetchMock = stubApi({
      "GET /api/dashboard/customers": json(200, page([customerRow()])),
      "GET /api/dashboard/customer-groups": json(200, { data: [group()] }),
      "POST /api/dashboard/customer-groups/3/assign": json(200, { data: { assigned: 4 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomersPage />);

    await user.selectOptions(await screen.findByRole("combobox", { name: "تعيين إلى مستوى" }), "3");
    await user.click(screen.getByRole("button", { name: "تعيين كل نتائج التصفية" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/customer-groups/3/assign")[0]?.body).toEqual({
        filter: { q: "النور" },
      }),
    );
  });

  it("D82 sales sees the group column but no selection, group filter or assignment", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/customers": json(200, page([customerRow()])) });
    renderWithQuery(<CustomersPage />, signedInAs("sales"));

    await screen.findByTestId("customer-7");
    expect(screen.queryByRole("checkbox")).toBeNull();
    expect(screen.queryByRole("combobox", { name: "المستوى" })).toBeNull();
    expect(callsTo(fetchMock, "GET /api/dashboard/customer-groups")).toHaveLength(0);
  });
});

describe("CustomerDetail groups and prices (BR-PR12, BR-PR13)", () => {
  it("shows the group in the header and saves a new group with the profile", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customer-groups": json(200, {
        data: [group(), group({ id: 5, name: "مكتبة كبيرة", discount_percent: "10.00" })],
      }),
      "PUT /api/dashboard/customers/7": json(200, { data: customerRow() }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <CustomerDetail
        customer={customerRow({ group: { id: 3, name: "موزع", discount_percent: "15.00", status: "active" } })}
      />,
    );

    expect(screen.getByTestId("customer-group")).toHaveTextContent("موزع (15%)");
    await screen.findByRole("option", { name: "مكتبة كبيرة (10%)" });
    await user.selectOptions(screen.getByLabelText("المستوى"), "5");
    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).toMatchObject({ customer_group_id: 5 }),
    );
  });

  it("leaves the group out of the save when it did not change", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customer-groups": json(200, { data: [group()] }),
      "PUT /api/dashboard/customers/7": json(200, { data: customerRow() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={customerRow()} />);

    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).not.toHaveProperty("customer_group_id");
  });

  it("D82 sales gets neither the group select nor the prices tab", () => {
    stubApi({});
    renderWithQuery(<CustomerDetail customer={customerRow()} />, signedInAs("sales"));

    expect(screen.queryByLabelText("المستوى")).toBeNull();
    expect(screen.queryByRole("tab", { name: "أسعار خاصة" })).toBeNull();
  });

  it("the owner opens the prices tab", () => {
    stubApi({ "GET /api/dashboard/customer-groups": json(200, { data: [] }) });
    renderWithQuery(<CustomerDetail customer={customerRow()} />);

    expect(screen.getByRole("tab", { name: "أسعار خاصة" })).toBeInTheDocument();
  });
});

describe("CustomerPricesTab (BR-PR13)", () => {
  it("lists the negotiated prices next to the base price, changes one and removes one", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customers/7/prices": json(200, page([price()])),
      "PUT /api/dashboard/customers/7/prices/11": json(200, { data: price({ unit_price: "80.00" }) }),
      "DELETE /api/dashboard/customers/7/prices/11": json(204),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerPricesTab customerId={7} />);

    const row = await screen.findByTestId("price-11");
    expect(row).toHaveTextContent("الأضواء علوم");
    expect(row).toHaveTextContent("90.00 ج.م / كرتونة");
    expect(row).toHaveTextContent("اتفاق الموسم");
    const input = within(row).getByRole("textbox", { name: "السعر الخاص الأضواء علوم" });
    await user.clear(input);
    await user.type(input, "80");
    await user.click(within(row).getByRole("button", { name: "حفظ" }));
    await waitFor(() =>
      expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7/prices/11")[0]?.body).toEqual({
        unit_price: "80",
        note: "اتفاق الموسم",
      }),
    );
    await user.click(within(row).getByRole("button", { name: "حذف السعر الخاص الأضواء علوم" }));

    await waitFor(() => expect(callsTo(fetchMock, "DELETE /api/dashboard/customers/7/prices/11")).toHaveLength(1));
  });

  it("asks for a product and a positive price before adding", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/customers/7/prices": json(200, page([])) });
    const user = userEvent.setup();
    renderWithQuery(<CustomerPricesTab customerId={7} />);

    expect(await screen.findByText("لا توجد أسعار خاصة لهذا العميل")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إضافة سعر خاص" }));

    expect(screen.getByRole("alert")).toHaveTextContent("اختر المنتج");
    expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7/prices/11")).toHaveLength(0);
  });
});
