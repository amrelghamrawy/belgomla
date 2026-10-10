import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerDetail } from "@/components/dashboard/customers/CustomerDetailPage";
import { CreateCustomerDialog, CustomersPage } from "@/components/dashboard/customers/CustomersPage";
import type { DashboardCustomer } from "@/lib/api/types";

import { address, page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

function dashboardCustomer(overrides: Partial<DashboardCustomer> = {}): DashboardCustomer {
  return {
    id: 7,
    name: "مكتبة الأمل",
    phone: "+201012345678",
    status: "active",
    status_label: "نشط",
    phone_verified_at: "2026-10-01T08:00:00Z",
    created_at: "2026-10-01T08:00:00Z",
    outstanding_amount: "350.00",
    created_by: null,
    approved_by: null,
    blocked_at: null,
    blocked_reason: null,
    addresses: [address({ id: 21, label: "المحل", is_default: true })],
    recent_orders: [{ id: 1, number: 1001, status: "delivered", total: "450.00", placed_at: "2026-10-02T08:00:00Z" }],
    ...overrides,
  };
}

const statement = {
  data: [
    {
      number: 1001,
      status: "delivered",
      status_label: "تم التوصيل",
      placed_at: "2026-10-02T08:00:00Z",
      delivered_at: "2026-10-03T08:00:00Z",
      order_total: "450.00",
      collected_amount: "100.00",
      balance: "350.00",
      collection_status: "partially_collected",
    },
  ],
  links: {},
  meta: { current_page: 1, last_page: 1, total: 1, per_page: 24, from: 1, to: 1, links: [], path: "" },
  customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
  summary: { orders_count: 1, total: "450.00", collected: "100.00", outstanding: "350.00" },
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

  it("D59 shows the statement summary and each order's balance", async () => {
    searchParams.value = new URLSearchParams("tab=statement");
    stubApi({ "GET /api/dashboard/customers/7/statement": json(200, statement) });

    renderWithQuery(<CustomerDetail customer={dashboardCustomer()} />);

    const summary = await screen.findByTestId("statement-summary");
    expect(summary).toHaveTextContent("المستحق (طلبات مسلّمة)350.00 ج.م");
    expect(screen.getByRole("link", { name: "#1001" })).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(screen.getByText("محصل جزئيًا")).toBeInTheDocument();
  });
});
