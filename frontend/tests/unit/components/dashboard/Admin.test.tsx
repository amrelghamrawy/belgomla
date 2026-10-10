import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuditDiff, AuditPage } from "@/components/dashboard/admin/AuditPage";
import { SettingsForm } from "@/components/dashboard/admin/SettingsPage";
import { CreateUserDialog, UsersPage } from "@/components/dashboard/admin/UsersPage";
import type { AuditLog, DashboardSettings } from "@/lib/api/types";
import { auditDiff, hoursBody, hoursRows } from "@/lib/dashboard/admin";

import { dashboardMe, tenantUser } from "../../dashboard-fixtures";
import { page, tenantSettings } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

function settings(overrides: Partial<DashboardSettings> = {}): DashboardSettings {
  return {
    ...tenantSettings({ working_hours: [{ day: 0, from: "09:00", to: "21:00" }] }),
    stale_pending_hours: 24,
    ...overrides,
  };
}

function auditEntry(overrides: Partial<AuditLog> = {}): AuditLog {
  return {
    id: 1,
    actor: { type: "tenant_user", id: 1 },
    action: "sku.pricing_updated",
    entity: { type: "skus", id: 5 },
    old_values: { base_price: "90.00", moq: 5 },
    new_values: { base_price: "85.00", moq: 5, tiers: [{ min_quantity: 10 }] },
    batch_id: "6f1c2b2e-3a4d-4e5f-8a9b-0c1d2e3f4a5b",
    ip: "127.0.0.1",
    created_at: "2026-10-10T08:00:00Z",
    ...overrides,
  };
}

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("working hours", () => {
  it("shows all seven days and sends only the open ones", () => {
    const rows = hoursRows([{ day: 5, from: "10:00", to: "14:00" }]);

    expect(rows).toHaveLength(7);
    expect(rows[5]).toEqual({ day: 5, open: true, from: "10:00", to: "14:00" });
    expect(hoursBody(rows)).toEqual([{ day: 5, from: "10:00", to: "14:00" }]);
  });
});

describe("SettingsForm", () => {
  it("previews the colors with the theme tokens before saving", async () => {
    stubApi({});
    renderWithQuery(<SettingsForm settings={settings()} />);

    fireEvent.change(screen.getByLabelText("اللون الأساسي", { selector: "input[type=color]" }), {
      target: { value: "#ff0000" },
    });

    await waitFor(() =>
      expect(screen.getByTestId("brand-preview").style.getPropertyValue("--primary")).toBe("#FF0000"),
    );
    expect(screen.getByTestId("brand-preview").style.getPropertyValue("--primary-foreground")).not.toBe("");
  });

  it("saves contact, hours, MOV and the switches in one PUT, then refreshes the store branding", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    const { client } = renderWithQuery(<SettingsForm settings={settings()} />);
    const invalidate = vi.spyOn(client, "invalidateQueries");

    await user.type(screen.getByLabelText("الهاتف"), "01001234567");
    await user.click(within(screen.getByTestId("hours-6")).getByRole("checkbox"));
    await user.click(screen.getByLabelText("حد أدنى لقيمة الطلب"));
    await user.type(screen.getByLabelText("الحد الأدنى (ج.م)"), "500");
    await user.click(screen.getByLabelText("مراجعة العملاء الجدد قبل تفعيلهم"));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      phone: "01001234567",
      whatsapp: null,
      working_hours: [
        { day: 0, from: "09:00", to: "21:00" },
        { day: 6, from: "09:00", to: "17:00" },
      ],
      mov_enabled: true,
      mov_amount: "500",
      require_customer_approval: true,
      stale_pending_hours: 24,
    });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["store", "tenant"] });
  });

  it("requires an amount when the minimum order value is on", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.click(screen.getByLabelText("حد أدنى لقيمة الطلب"));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("أدخل قيمة الحد الأدنى")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);
  });
});

describe("UsersPage", () => {
  it("marks the logged-in user and shows cannot_disable_self", async () => {
    stubApi({
      "GET /api/dashboard/users": json(200, {
        data: [tenantUser(), tenantUser({ id: 2, name: "سارة", email: "sara@alnoor.test" })],
      }),
      "GET /api/dashboard/me": json(200, { data: dashboardMe() }),
      "PATCH /api/dashboard/users/1": envelopeError(409, "cannot_disable_self", "لا يمكنك إيقاف حسابك"),
    });
    const user = userEvent.setup();
    renderWithQuery(<UsersPage />);

    const me = await screen.findByTestId("user-1");
    expect(await within(me).findByText("أنت")).toBeInTheDocument();
    await user.click(within(me).getByRole("button", { name: "إيقاف" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("لا يمكنك إيقاف حسابك"));
  });

  it("enables a disabled user", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/users": json(200, { data: [tenantUser({ id: 2, status: "disabled" })] }),
      "GET /api/dashboard/me": json(200, { data: dashboardMe() }),
      "PATCH /api/dashboard/users/2": json(200, { data: tenantUser({ id: 2 }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<UsersPage />);

    await user.click(await screen.findByRole("button", { name: "تفعيل" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "PATCH /api/dashboard/users/2")[0]?.body).toEqual({ status: "active" }),
    );
  });

  it("creates a user and shows email_taken", async () => {
    stubApi({ "POST /api/dashboard/users": envelopeError(409, "email_taken", "البريد الإلكتروني مستخدم بالفعل") });
    const user = userEvent.setup();
    renderWithQuery(<CreateUserDialog onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم"), "سارة");
    await user.type(screen.getByLabelText("البريد الإلكتروني"), "sara@alnoor.test");
    await user.type(screen.getByLabelText("كلمة المرور"), "secret123");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("البريد الإلكتروني مستخدم بالفعل");
  });
});

describe("audit", () => {
  it("diffs old and new values field by field and marks what changed", () => {
    expect(auditDiff({ base_price: "90.00", moq: 5 }, { base_price: "85.00", moq: 5, tiers: [] })).toEqual([
      { field: "base_price", before: "90.00", after: "85.00", changed: true },
      { field: "moq", before: "5", after: "5", changed: false },
      { field: "tiers", before: null, after: "[]", changed: true },
    ]);
  });

  it("renders the diff table", () => {
    renderWithQuery(<AuditDiff entry={auditEntry()} />);

    const rows = within(screen.getByTestId("audit-diff")).getAllByRole("row").slice(1);
    expect(rows.map((row) => row.getAttribute("data-changed"))).toEqual(["true", "false", "true"]);
  });

  it("expands an entry and filters by its batch", async () => {
    stubApi({ "GET /api/dashboard/audit": json(200, page([auditEntry()])) });
    const user = userEvent.setup();
    renderWithQuery(<AuditPage />);

    const row = await screen.findByTestId("audit-1");
    await user.click(within(row).getByRole("button", { name: "التفاصيل" }));
    expect(screen.getByTestId("audit-diff")).toBeInTheDocument();
    await user.click(within(row).getByRole("button", { name: "عرض الدفعة كلها" }));

    expect(router.replace).toHaveBeenCalledWith("/?batch_id=6f1c2b2e-3a4d-4e5f-8a9b-0c1d2e3f4a5b", { scroll: false });
  });

  it("refuses a batch id that is not a uuid before asking the API", async () => {
    stubApi({ "GET /api/dashboard/audit": json(200, page([])) });
    const user = userEvent.setup();
    renderWithQuery(<AuditPage />);

    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    await user.type(screen.getByLabelText("رقم الدفعة"), "abc");
    await user.click(screen.getByRole("button", { name: "تطبيق" }));

    expect(screen.getByRole("alert")).toHaveTextContent("رقم الدفعة غير صحيح");
    expect(router.replace).not.toHaveBeenCalled();
  });
});
