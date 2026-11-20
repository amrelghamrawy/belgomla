import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerDetail } from "@/components/dashboard/customers/CustomerDetailPage";
import { CreateCustomerDialog, CustomersPage } from "@/components/dashboard/customers/CustomersPage";
import type { DashboardCustomer } from "@/lib/api/types";

import { signedInAs } from "../../dashboard-fixtures";
import { address, page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery as renderBare, router, searchParams } from "../../render";

/** These screens hide what the role may not do (D82): render them for a signed-in owner unless a test picks a role. */
function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

function dashboardCustomer(overrides: Partial<DashboardCustomer> = {}): DashboardCustomer {
  return {
    id: 7,
    name: "مكتبة الأمل",
    phone: "+201012345678",
    order_notifications: true,
    status: "active",
    status_label: "نشط",
    phone_verified_at: "2026-10-01T08:00:00Z",
    created_at: "2026-10-01T08:00:00Z",
    outstanding_amount: "350.00",
    created_by: null,
    approved_by: null,
    blocked_at: null,
    blocked_reason: null,
    credit_limit: null,
    payment_terms_days: null,
    group: null,
    addresses: [address({ id: 21, label: "المحل", is_default: true })],
    recent_orders: [{ id: 1, number: 1001, status: "delivered", total: "450.00", placed_at: "2026-10-02T08:00:00Z" }],
    ...overrides,
  };
}

const statement = {
  data: [
    {
      date: "2026-10-03T08:00:00Z",
      type: "order",
      type_label: "طلب مسلَّم",
      order_number: 1001,
      return_number: null,
      debit: "450.00",
      credit: "0.00",
      balance: "450.00",
    },
    {
      date: "2026-10-04T08:00:00Z",
      type: "return",
      type_label: "مرتجع",
      order_number: 1001,
      return_number: 1,
      debit: "0.00",
      credit: "100.00",
      balance: "350.00",
    },
  ],
  links: {},
  meta: {
    current_page: 1,
    last_page: 1,
    total: 2,
    per_page: 24,
    from: 1,
    to: 2,
    links: [],
    path: "",
    opening_balance: "0.00",
    closing_balance: "350.00",
  },
  customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
  summary: {
    orders_count: 1,
    total: "350.00",
    collected: "0.00",
    outstanding: "350.00",
    credit_limit: "5000.00",
    payment_terms_days: 30,
    available_credit: "4650.00",
    credit_balance: "40.00",
    overdue: "350.00",
  },
};

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
  router.push.mockReset();
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("CustomersPage", () => {
  it("lists customers with status and outstanding amount, linking to their page", async () => {
    stubApi({
      "GET /api/dashboard/customers": json(
        200,
        page([
          dashboardCustomer(),
          dashboardCustomer({ id: 8, name: "مكتبة جديدة", status: "invited", outstanding_amount: "0.00" }),
        ]),
      ),
    });

    renderWithQuery(<CustomersPage />);

    const row = await screen.findByTestId("customer-7");
    expect(within(row).getByRole("link", { name: "مكتبة الأمل" })).toHaveAttribute("href", "/dashboard/customers/7");
    expect(row).toHaveTextContent("350.00 ج.م");
    expect(within(screen.getByTestId("customer-8")).getByText("مدعو")).toBeInTheDocument();
  });
});

describe("CreateCustomerDialog", () => {
  it("BR-CU08 creates an invited customer, sends the invitation and shows the channel it went out on", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/customers": json(201, { data: dashboardCustomer({ id: 9, status: "invited" }) }),
      "POST /api/dashboard/customers/9/invite": json(200, {
        data: { sent_via: "whatsapp", expires_at: "2026-10-17T08:00:00Z", sent_count: 1 },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CreateCustomerDialog onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم"), "مكتبة الأمل");
    await user.type(screen.getByLabelText("الهاتف"), "01012345678");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/dashboard/customers/9"));
    expect(callsTo(fetchMock, "POST /api/dashboard/customers")[0]?.body).toEqual({
      name: "مكتبة الأمل",
      phone: "01012345678",
    });
    expect(toast.success).toHaveBeenCalledWith(expect.stringContaining("تم إرسال الدعوة عبر واتساب"));
  });

  it("skips the invitation when unticked and shows phone_taken", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/customers": envelopeError(409, "phone_taken", "رقم الهاتف مسجل لعميل آخر"),
    });
    const user = userEvent.setup();
    renderWithQuery(<CreateCustomerDialog onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم"), "مكتبة الأمل");
    await user.type(screen.getByLabelText("الهاتف"), "01012345678");
    await user.click(screen.getByLabelText("إرسال الدعوة الآن"));
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("رقم الهاتف مسجل لعميل آخر");
    expect(callsTo(fetchMock, "POST /api/dashboard/customers/9/invite")).toHaveLength(0);
  });
});

describe("CustomerDetail", () => {
  it("BR-O11 offers a new order for an active customer to who may create orders", () => {
    stubApi({});
    const { rerender } = renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    expect(screen.getByRole("link", { name: "طلب جديد لهذا العميل" })).toHaveAttribute(
      "href",
      "/dashboard/orders/new?customer=7",
    );

    rerender(<CustomerDetail customer={dashboardCustomer({ status: "blocked", blocked_reason: "لم يسدد" })} />);

    expect(screen.queryByRole("link", { name: "طلب جديد لهذا العميل" })).toBeNull();
  });

  it("D82 the accountant gets no new-order shortcut", () => {
    stubApi({});
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />, signedInAs("accountant"));

    expect(screen.queryByRole("link", { name: "طلب جديد لهذا العميل" })).toBeNull();
  });

  it("offers the invitation only to an invited customer and approval only while pending", () => {
    stubApi({});
    const { rerender } = renderWithQuery(<CustomerDetail customer={dashboardCustomer({ status: "invited" })} />);

    expect(screen.getByRole("button", { name: "إرسال الدعوة" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "الموافقة على العميل" })).not.toBeInTheDocument();

    rerender(<CustomerDetail customer={dashboardCustomer({ status: "pending_approval" })} />);

    expect(screen.getByRole("button", { name: "الموافقة على العميل" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إرسال الدعوة" })).not.toBeInTheDocument();
  });

  it("blocks with a reason and unblocks a blocked customer", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/customers/7/block": json(200, {
        data: dashboardCustomer({ status: "blocked", blocked_reason: "لم يسدد" }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    await user.click(screen.getByRole("button", { name: "حظر العميل" }));
    await user.type(screen.getByLabelText("السبب"), "لم يسدد");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "حظر العميل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم حظر العميل"));
    expect(callsTo(fetchMock, "POST /api/dashboard/customers/7/block")[0]?.body).toEqual({ reason: "لم يسدد" });
  });

  it("shows invalid_transition from the backend", async () => {
    stubApi({
      "POST /api/dashboard/customers/7/approve": envelopeError(
        409,
        "invalid_transition",
        "لا يمكن الموافقة على عميل بهذه الحالة",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer({ status: "pending_approval" })} />);

    await user.click(screen.getByRole("button", { name: "الموافقة على العميل" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("لا يمكن الموافقة على عميل بهذه الحالة"));
  });

  it("D54 saves the name with the full address list, keeping ids and dropping removed rows", async () => {
    const fetchMock = stubApi({
      "PUT /api/dashboard/customers/7": json(200, {
        data: dashboardCustomer({ name: "مكتبة الأمل الجديدة", addresses: [] }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <CustomerDetail
        customer={dashboardCustomer({
          addresses: [address({ id: 21, label: "المحل" }), address({ id: 22, label: "المخزن" })],
        })}
      />,
    );

    await user.clear(screen.getByLabelText("الاسم"));
    await user.type(screen.getByLabelText("الاسم"), "مكتبة الأمل الجديدة");
    await user.click(screen.getAllByRole("button", { name: "حذف العنوان" })[1]!);
    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body as {
      name: string;
      addresses: { id?: number }[];
    };
    expect(body.name).toBe("مكتبة الأمل الجديدة");
    expect(body.addresses.map((row) => row.id)).toEqual([21]);
  });

  it("BR-C08 shows the credit picture and the ledger with its running balance and closing balance", async () => {
    searchParams.value = new URLSearchParams("tab=statement");
    stubApi({
      "GET /api/dashboard/customers/7/statement": json(200, statement),
      "GET /api/dashboard/customers/7/credits": json(200, { data: [] }),
    });

    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    const summary = await screen.findByTestId("statement-summary");
    expect(summary).toHaveTextContent("المستحق (طلبات مسلّمة)350.00 ج.م");
    expect(summary).toHaveTextContent("المتاح من الحد4,650.00 ج.م");
    expect(summary).toHaveTextContent("متأخر السداد350.00 ج.م");
    const ledger = screen.getByTestId("ledger");
    expect(within(ledger).getByRole("link", { name: "مرتجع #1 من طلب #1001" })).toHaveAttribute(
      "href",
      "/dashboard/orders/1001",
    );
    expect(screen.getByTestId("ledger-opening")).toHaveTextContent("0.00 ج.م");
    expect(screen.getByTestId("ledger-closing")).toHaveTextContent("350.00 ج.م");
  });

  it("D60 the statement range goes into the URL and the print button prints", async () => {
    searchParams.value = new URLSearchParams("tab=statement&from=2026-10-01");
    const fetchMock = stubApi({
      "GET /api/dashboard/customers/7/statement": json(200, statement),
      "GET /api/dashboard/customers/7/credits": json(200, { data: [] }),
    });
    const print = vi.spyOn(window, "print").mockImplementation(() => undefined);
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    await screen.findByTestId("ledger");
    expect(
      Object.fromEntries(callsTo(fetchMock, "GET /api/dashboard/customers/7/statement")[0]?.url.searchParams ?? []),
    ).toMatchObject({ from: "2026-10-01" });
    await user.click(screen.getByRole("button", { name: "طباعة كشف الحساب" }));

    expect(print).toHaveBeenCalled();
  });

  it("BR-C07 lists the customer's credits and voids an unused one", async () => {
    searchParams.value = new URLSearchParams("tab=statement");
    const credit = {
      id: 9,
      customer_id: 7,
      source_return_id: 3,
      amount: "40.00",
      remaining: "40.00",
      note: null,
      created_by: 1,
      created_at: "2026-10-04T08:00:00Z",
      is_voided: false,
      voided_at: null,
      void_reason: null,
      voided_by: null,
    };
    const fetchMock = stubApi({
      "GET /api/dashboard/customers/7/statement": json(200, statement),
      "GET /api/dashboard/customers/7/credits": json(200, {
        data: [credit, { ...credit, id: 10, remaining: "10.00" }],
      }),
      "POST /api/dashboard/credits/9/void": json(200, { data: { ...credit, is_voided: true } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    const credits = await screen.findByTestId("customer-credits");
    expect(within(credits).getAllByRole("button", { name: "إلغاء الرصيد" })).toHaveLength(1);
    await user.click(within(credits).getByRole("button", { name: "إلغاء الرصيد" }));
    await user.type(screen.getByLabelText("السبب"), "خطأ");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "إلغاء الرصيد" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم إلغاء الرصيد"));
    expect(callsTo(fetchMock, "POST /api/dashboard/credits/9/void")[0]?.headers["Idempotency-Key"]).toMatch(
      /^void-credit-/,
    );
  });

  it("BR-C05 BR-C06 saves the credit limit and payment terms with the profile, blank as none", async () => {
    const fetchMock = stubApi({
      "PUT /api/dashboard/customers/7": json(200, { data: dashboardCustomer({ credit_limit: "2000.00" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    await user.type(screen.getByLabelText("حد الائتمان"), "2000");
    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalled());
    expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).toMatchObject({
      credit_limit: "2000",
      payment_terms_days: null,
    });
  });

  it("D82 an accountant changes only the credit terms: the name stays and no addresses are sent", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/customers/7": json(200, { data: dashboardCustomer() }) });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />, signedInAs("accountant"));

    expect(screen.getByLabelText("الاسم")).toBeDisabled();
    expect(screen.queryByRole("button", { name: "إضافة عنوان" })).toBeNull();
    await user.type(screen.getByLabelText("حد الائتمان"), "3000");
    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).toEqual({
        name: "مكتبة الأمل",
        credit_limit: "3000",
        payment_terms_days: null,
      }),
    );
  });

  it("D82 sales edits the profile but not the credit terms, which are left out of the save", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/customers/7": json(200, { data: dashboardCustomer() }) });
    const user = userEvent.setup();
    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />, signedInAs("sales"));

    expect(screen.getByLabelText("حد الائتمان")).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "حفظ البيانات" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/customers/7")[0]?.body).not.toHaveProperty("credit_limit");
  });
});
