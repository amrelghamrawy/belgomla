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

const DEFAULT_INVITATION =
  "أهلًا {اسم_العميل}، دعاك {اسم_المتجر} للطلب من متجره.\nاضغط الرابط لتحديد كلمة المرور وتفعيل حسابك (صالح {عدد_الأيام} أيام):";

const ORDER_MESSAGE_DEFAULTS = {
  confirmed:
    "أهلًا {اسم_العميل}، تم تأكيد طلبك رقم {رقم_الطلب} من {اسم_المتجر} بإجمالي {الإجمالي}. سنبلغك عند خروجه للتوصيل.",
  preparing: "أهلًا {اسم_العميل}، طلبك رقم {رقم_الطلب} من {اسم_المتجر} قيد التجهيز الآن.",
  out_for_delivery: "أهلًا {اسم_العميل}، طلبك رقم {رقم_الطلب} من {اسم_المتجر} خرج للتوصيل وفي الطريق إليك.",
  delivered: "أهلًا {اسم_العميل}، تم توصيل طلبك رقم {رقم_الطلب} من {اسم_المتجر}. شكرًا لتعاملك معنا.",
  cancelled: "أهلًا {اسم_العميل}، تم إلغاء طلبك رقم {رقم_الطلب} من {اسم_المتجر}. السبب: {سبب_الإلغاء}",
  discount: "أهلًا {اسم_العميل}، تم تطبيق خصم على طلبك رقم {رقم_الطلب} من {اسم_المتجر}، والإجمالي الآن {الإجمالي}.",
  edited:
    "أهلًا {اسم_العميل}، تم تعديل أصناف طلبك رقم {رقم_الطلب} من {اسم_المتجر}، والإجمالي الآن {الإجمالي}. التفاصيل في صفحة الطلب.",
};

function settings(overrides: Partial<DashboardSettings> = {}): DashboardSettings {
  return {
    ...tenantSettings({ working_hours: [{ day: 0, from: "09:00", to: "21:00" }] }),
    stale_pending_hours: 24,
    invitation_message: null,
    invitation_message_default: DEFAULT_INVITATION,
    default_payment_terms_days: null,
    default_credit_limit: null,
    order_notify_statuses: ["confirmed", "out_for_delivery", "delivered", "cancelled"],
    order_status_messages: {},
    order_status_message_defaults: ORDER_MESSAGE_DEFAULTS,
    pending_expiry_hours: null,
    max_discount_percent_sales: null,
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
      invitation_message: null,
    });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["store", "tenant"] });
  });

  it("BR-C05 BR-C06 saves the credit terms new customers start with, blank as none", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings({ default_credit_limit: "1000.00" })} />);

    await user.type(screen.getByLabelText("مهلة السداد الافتراضية (يوم)"), "30");
    await user.clear(screen.getByLabelText("حد الائتمان الافتراضي"));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      default_payment_terms_days: 30,
      default_credit_limit: null,
    });
  });

  it("BR-C06 refuses payment terms over a year", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.type(screen.getByLabelText("مهلة السداد الافتراضية (يوم)"), "400");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("رقم صحيح أكبر من صفر")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);
  });

  it("BR-INV07 D81 turns the automatic expiry on with a number of hours, and off when emptied", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings({ pending_expiry_hours: 72 })} />);
    const field = screen.getByLabelText("إلغاء الطلبات غير المؤكدة تلقائيًا بعد (ساعة)");

    expect(field).toHaveValue("72");
    await user.clear(field);
    await user.type(field, "48");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({ pending_expiry_hours: 48 });

    await user.clear(field);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(2));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[1]?.body).toMatchObject({ pending_expiry_hours: null });
  });

  it("D85 sets the sales discount cap, clears it when emptied, and refuses 100 or more", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings({ max_discount_percent_sales: "10.00" })} />);
    const field = screen.getByLabelText("أقصى خصم لمستخدم المبيعات على الصنف (%)");

    expect(field).toHaveValue("10.00");
    await user.clear(field);
    await user.type(field, "100");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    expect(await screen.findByText("نسبة أكبر من 0 وأقل من 100، برقمين عشريين على الأكثر")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);

    await user.clear(field);
    await user.type(field, "12.5");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      max_discount_percent_sales: "12.5",
    });

    await user.clear(field);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(2));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[1]?.body).toMatchObject({
      max_discount_percent_sales: null,
    });
  });

  it("D85 BR-N03 a store may message customers about discounts, off by default", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);
    const toggle = screen.getByLabelText("أرسل رسالة عند: خصم على الطلب");

    expect(toggle).not.toBeChecked();
    await user.click(toggle);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      order_notify_statuses: ["confirmed", "out_for_delivery", "delivered", "cancelled", "discount"],
    });
  });

  it("D87 a store may stop messaging customers about line edits, on by default", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(
      <SettingsForm
        settings={settings({
          order_notify_statuses: ["confirmed", "out_for_delivery", "delivered", "cancelled", "edited"],
        })}
      />,
    );
    const toggle = screen.getByLabelText("أرسل رسالة عند: تعديل أصناف الطلب");

    expect(toggle).toBeChecked();
    expect(screen.getByLabelText("نص رسالة «تعديل أصناف الطلب»")).toHaveValue(ORDER_MESSAGE_DEFAULTS.edited);
    await user.click(toggle);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      order_notify_statuses: ["confirmed", "out_for_delivery", "delivered", "cancelled"],
    });
  });

  it("BR-INV07 refuses an expiry shorter than the stale hours", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.type(screen.getByLabelText("إلغاء الطلبات غير المؤكدة تلقائيًا بعد (ساعة)"), "12");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("يجب ألا تقل عن مدة اعتبار الطلب متأخرًا (24 ساعة)")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);
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

describe("invitation message", () => {
  it("starts from the default text and previews it with the store name", () => {
    stubApi({});
    renderWithQuery(<SettingsForm settings={settings()} />);

    expect(screen.getByLabelText("نص الرسالة")).toHaveValue(DEFAULT_INVITATION);
    expect(screen.getByTestId("invitation-preview")).toHaveTextContent(
      "أهلًا أحمد محمود، دعاك مكتبة النور للطلب من متجره.",
    );
    expect(screen.getByTestId("invitation-preview")).toHaveTextContent("(صالح 7 أيام)");
  });

  it("inserts a placeholder at the caret and saves the store's own text", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);
    const box = screen.getByLabelText("نص الرسالة");

    await user.clear(box);
    await user.type(box, "يا ");
    // The invitation card comes before the order-message card, which has the same chips.
    await user.click(screen.getAllByRole("button", { name: "اسم العميل" })[0]!);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      invitation_message: "يا {اسم_العميل}",
    });
    expect(screen.getByTestId("invitation-preview")).toHaveTextContent("يا أحمد محمود");
  });

  it("restores the default text, which is sent as null", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings({ invitation_message: "نص خاص" })} />);

    expect(screen.getByLabelText("نص الرسالة")).toHaveValue("نص خاص");
    await user.click(screen.getAllByRole("button", { name: "استرجاع النص الافتراضي" })[0]!);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(screen.getByLabelText("نص الرسالة")).toHaveValue(DEFAULT_INVITATION);
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({ invitation_message: null });
  });

  it("blocks an unknown placeholder before saving", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    fireEvent.change(screen.getByLabelText("نص الرسالة"), { target: { value: "أهلًا {الاسم}" } });
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("متغير غير معروف: {الاسم}. استخدم الأزرار لإضافة المتغيرات.")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);
  });

  it("shows the backend's error under the box", async () => {
    stubApi({
      "PUT /api/dashboard/settings": envelopeError(422, "validation_failed", "بيانات غير صحيحة", {
        invitation_message: ["نص مرفوض"],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("نص مرفوض")).toBeInTheDocument();
  });
});

describe("order status messages", () => {
  const card = () => within(screen.getByTestId("order-messages"));

  it("BR-N03 lists every status, the notified ones open with the default text previewed", () => {
    stubApi({});
    renderWithQuery(<SettingsForm settings={settings()} />);

    expect(card().getByLabelText("أرسل رسالة عند: مؤكد")).toBeChecked();
    expect(card().getByLabelText("أرسل رسالة عند: قيد التجهيز")).not.toBeChecked();
    expect(card().queryByLabelText("نص رسالة «قيد التجهيز»")).not.toBeInTheDocument();
    expect(card().getByLabelText("نص رسالة «مؤكد»")).toHaveValue(ORDER_MESSAGE_DEFAULTS.confirmed);
    expect(screen.getByTestId("order-message-confirmed-preview")).toHaveTextContent(
      "أهلًا أحمد محمود، تم تأكيد طلبك رقم 1024 من مكتبة النور بإجمالي 1,250.00 ج.م.",
    );
  });

  it("BR-N03 the cancel reason chip is offered in the cancelled text only", () => {
    stubApi({});
    renderWithQuery(<SettingsForm settings={settings()} />);

    expect(
      within(screen.getByTestId("order-message-cancelled")).getByRole("button", { name: "سبب الإلغاء" }),
    ).toBeInTheDocument();
    expect(
      within(screen.getByTestId("order-message-confirmed")).queryByRole("button", { name: "سبب الإلغاء" }),
    ).toBeNull();
  });

  it("BR-N03 saves the statuses in the platform order and the store's own words, defaults as null", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.click(card().getByLabelText("أرسل رسالة عند: قيد التجهيز"));
    await user.click(card().getByLabelText("أرسل رسالة عند: تم التوصيل"));
    const box = card().getByLabelText("نص رسالة «قيد التجهيز»");
    await user.clear(box);
    await user.type(box, "طلبك ");
    await user.click(within(screen.getByTestId("order-message-preparing")).getByRole("button", { name: "رقم الطلب" }));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
      order_notify_statuses: ["confirmed", "preparing", "out_for_delivery", "cancelled"],
      order_status_messages: {
        confirmed: null,
        preparing: "طلبك {رقم_الطلب}",
        out_for_delivery: null,
        delivered: null,
        cancelled: null,
      },
    });
  });

  it("BR-O11 a save keeps the store's wording of the placed message, which has no editor here", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/settings": json(200, { data: settings() }) });
    const user = userEvent.setup();
    renderWithQuery(
      <SettingsForm settings={settings({ order_status_messages: { placed: "سجلنا طلبك {رقم_الطلب}" } })} />,
    );

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() =>
      expect(callsTo(fetchMock, "PUT /api/dashboard/settings")[0]?.body).toMatchObject({
        order_status_messages: { placed: "سجلنا طلبك {رقم_الطلب}", confirmed: null },
      }),
    );
  });

  it("BR-N03 starts from the store's saved words and restores the default", async () => {
    stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings({ order_status_messages: { delivered: "وصل {رقم_الطلب}" } })} />);
    const box = card().getByLabelText("نص رسالة «تم التوصيل»");

    expect(box).toHaveValue("وصل {رقم_الطلب}");
    await user.click(
      within(screen.getByTestId("order-message-delivered")).getByRole("button", { name: "استرجاع النص الافتراضي" }),
    );
    expect(box).toHaveValue(ORDER_MESSAGE_DEFAULTS.delivered);
  });

  it("BR-N03 blocks the cancel reason outside the cancelled text before saving", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    fireEvent.change(card().getByLabelText("نص رسالة «مؤكد»"), { target: { value: "السبب {سبب_الإلغاء}" } });
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(
      await card().findByText("متغير غير معروف: {سبب_الإلغاء}. استخدم الأزرار لإضافة المتغيرات."),
    ).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/settings")).toHaveLength(0);
  });

  it("shows the backend's error under the status box", async () => {
    stubApi({
      "PUT /api/dashboard/settings": envelopeError(422, "validation_failed", "بيانات غير صحيحة", {
        "order_status_messages.delivered": ["نص التوصيل مرفوض"],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<SettingsForm settings={settings()} />);

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(
      await within(screen.getByTestId("order-message-delivered")).findByText("نص التوصيل مرفوض"),
    ).toBeInTheDocument();
  });
});

describe("UsersPage", () => {
  it("D82 changes a user's role from the list and shows last_owner", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/users": json(200, {
        data: [tenantUser(), tenantUser({ id: 2, name: "سارة", email: "sara@alnoor.test", role: "sales" })],
      }),
      "GET /api/dashboard/me": json(200, { data: dashboardMe() }),
      "PATCH /api/dashboard/users/2": json(200, { data: tenantUser({ id: 2, role: "warehouse" }) }),
      "PATCH /api/dashboard/users/1": envelopeError(409, "last_owner", "لا يمكن تغيير دور آخر مالك نشط أو تعطيله"),
    });
    const user = userEvent.setup();
    renderWithQuery(<UsersPage />);

    await user.selectOptions(await screen.findByRole("combobox", { name: "دور سارة" }), "warehouse");
    await waitFor(() =>
      expect(callsTo(fetchMock, "PATCH /api/dashboard/users/2")[0]?.body).toEqual({ role: "warehouse" }),
    );

    await user.selectOptions(screen.getByRole("combobox", { name: "دور أحمد" }), "manager");
    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("لا يمكن تغيير دور آخر مالك نشط أو تعطيله"));
  });

  it("D82 adds a user with a role, sales by default", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/users": json(201, { data: tenantUser({ id: 3 }) }) });
    const user = userEvent.setup();
    renderWithQuery(<CreateUserDialog onClose={() => undefined} />);

    expect(screen.getByLabelText("الدور")).toHaveValue("sales");
    expect(screen.getByText("الطلبات والعملاء")).toBeInTheDocument();
    await user.type(screen.getByLabelText("الاسم"), "منى");
    await user.type(screen.getByLabelText("البريد الإلكتروني"), "mona@alnoor.test");
    await user.type(screen.getByLabelText("كلمة المرور"), "secret123");
    await user.selectOptions(screen.getByLabelText("الدور"), "accountant");
    expect(screen.getByText("التحصيل وحدود الائتمان والتقارير")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/users")[0]?.body).toEqual({
        name: "منى",
        email: "mona@alnoor.test",
        password: "secret123",
        role: "accountant",
      }),
    );
  });

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
