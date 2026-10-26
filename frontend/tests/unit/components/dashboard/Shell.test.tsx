import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardShell } from "@/components/dashboard/shell/DashboardShell";
import { isActive } from "@/components/dashboard/shell/DashboardNav";
import { NOTIFICATIONS_POLL_MS } from "@/lib/dashboard/notifications";

import { dashboardMe, notification, shellRoutes, stubDashboard } from "../../dashboard-fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { pathname, renderWithQuery, router } from "../../render";

beforeEach(() => {
  router.replace.mockReset();
});

describe("DashboardShell", () => {
  it("shows the tenant, the user menu and the page once the session is known", async () => {
    stubDashboard();

    renderWithQuery(
      <DashboardShell>
        <p>محتوى الصفحة</p>
      </DashboardShell>,
    );

    expect(await screen.findByTestId("tenant-name")).toHaveTextContent("مكتبة النور");
    expect(screen.getByRole("main")).toHaveTextContent("محتوى الصفحة");
    expect(screen.getByRole("button", { name: "حساب المستخدم" })).toBeInTheDocument();
  });

  it("sends a guest to the dashboard login with the current page as next", async () => {
    window.history.pushState({}, "", "/dashboard/orders?status=pending");
    stubApi({ ...shellRoutes(), "GET /api/dashboard/me": envelopeError(401, "unauthenticated", "يجب تسجيل الدخول") });

    renderWithQuery(
      <DashboardShell>
        <p>سري</p>
      </DashboardShell>,
    );

    await waitFor(() =>
      expect(router.replace).toHaveBeenCalledWith("/dashboard/login?next=%2Fdashboard%2Forders%3Fstatus%3Dpending"),
    );
    expect(screen.getByText("سري")).not.toBeVisible();
    window.history.pushState({}, "", "/");
  });

  it("shows the error when the session cannot be read", async () => {
    stubApi({ ...shellRoutes(), "GET /api/dashboard/me": envelopeError(403, "tenant_suspended", "تم إيقاف المتجر") });

    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    expect(await screen.findByText("تم إيقاف المتجر")).toBeInTheDocument();
  });

  it("logs out from the user menu and reloads on the login page", async () => {
    const assign = vi.fn();
    vi.stubGlobal("location", { ...window.location, assign });
    const fetchMock = stubDashboard({ "POST /api/dashboard/auth/logout": json(204) });
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "حساب المستخدم" }));
    await user.click(await screen.findByRole("menuitem", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(assign).toHaveBeenCalledWith("/dashboard/login"));
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/logout")).toHaveLength(1);
  });

  it("marks the current section in the navigation", async () => {
    pathname.value = "/dashboard/orders/1001";
    stubDashboard();

    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    const nav = await screen.findByRole("navigation", { name: "القائمة الرئيسية" });
    expect(within(nav).getByRole("link", { name: "الطلبات" })).toHaveAttribute("aria-current", "page");
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).not.toHaveAttribute("aria-current");
  });
});

describe("DashboardShell sidebar", () => {
  const sidebar = () => document.querySelector('[data-slot="sidebar"]');

  beforeEach(() => {
    document.cookie = "sidebar_state=; max-age=0; path=/";
  });

  it("collapses the sidebar to icons from the toggle and remembers it in a cookie", async () => {
    stubDashboard();
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    const toggle = await screen.findByRole("button", { name: "إظهار القائمة أو إخفاؤها" });
    expect(sidebar()).toHaveAttribute("data-state", "expanded");
    await user.click(toggle);

    expect(sidebar()).toHaveAttribute("data-state", "collapsed");
    expect(sidebar()).toHaveAttribute("data-collapsible", "icon");
    expect(document.cookie).toContain("sidebar_state=false");
  });

  it("opens collapsed when the last visit left it collapsed", async () => {
    document.cookie = "sidebar_state=false; path=/";
    stubDashboard();

    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await screen.findByTestId("tenant-name");
    expect(sidebar()).toHaveAttribute("data-state", "collapsed");
  });
});

describe("isActive", () => {
  it("matches the overview only on /dashboard and other items on their sub-pages", () => {
    expect(isActive("/dashboard", "/dashboard")).toBe(true);
    expect(isActive("/dashboard", "/dashboard/orders")).toBe(false);
    expect(isActive("/dashboard/orders", "/dashboard/orders/12")).toBe(true);
    expect(isActive("/dashboard/orders", "/dashboard/ordersx")).toBe(false);
  });
});

describe("DashboardNav per role (D82)", () => {
  it("shows a sales user only the sections their role opens", async () => {
    stubApi({ ...shellRoutes(), "GET /api/dashboard/me": json(200, { data: dashboardMe({ role: "sales" }) }) });
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    const nav = await screen.findByRole("navigation", { name: "القائمة الرئيسية" });
    expect(within(nav).getByRole("link", { name: "الطلبات" })).toBeInTheDocument();
    expect(within(nav).getByRole("link", { name: "العملاء" })).toBeInTheDocument();
    for (const hidden of ["الإعدادات", "المستخدمون", "سجل التغييرات", "التقارير", "التحصيلات", "العروض"]) {
      expect(within(nav).queryByRole("link", { name: hidden })).toBeNull();
    }
  });

  it("shows the owner every section", async () => {
    stubApi(shellRoutes());
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    const nav = await screen.findByRole("navigation", { name: "القائمة الرئيسية" });
    expect(within(nav).getByRole("link", { name: "الإعدادات" })).toBeInTheDocument();
    expect(within(nav).getByRole("link", { name: "المستخدمون" })).toBeInTheDocument();
    expect(within(nav).getByRole("link", { name: "العروض" })).toHaveAttribute("href", "/dashboard/promotions");
  });
});

describe("NotificationsBell", () => {
  it("polls the unread count every 30 seconds", () => {
    expect(NOTIFICATIONS_POLL_MS).toBe(30_000);
  });

  it("shows the unread count and the newest notifications linking to their orders", async () => {
    stubApi(shellRoutes([notification(), notification({ id: 2, read_at: "2026-10-10T09:00:00Z" })], 1));
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    const bell = await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" });
    expect(screen.getByTestId("unread-count")).toHaveTextContent("1");
    await user.click(bell);

    const links = await screen.findAllByRole("link", { name: /طلب #1001 من مكتبة الأمل/ });
    expect(links[0]).toHaveAttribute("href", "/dashboard/orders/1001");
  });

  it("BR-N02 a dropped store WhatsApp number links to settings", async () => {
    stubApi(
      shellRoutes(
        [
          notification({
            id: 9,
            type: "whatsapp_dropped",
            type_label: "انفصل رقم واتساب المتجر",
            details: { phone: "+201001234567", reason: "logged_out" },
          }),
        ],
        1,
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" }));

    const link = await screen.findByRole("link", { name: /اربطه من جديد من الإعدادات/ });
    expect(link).toHaveAttribute("href", "/dashboard/settings");
    expect(link).toHaveTextContent("01001234567");
  });

  it("BR-O14 a new pre-order links to the pre-order", async () => {
    stubApi(shellRoutes([notification({ id: 5, type: "new_preorder", type_label: "حجز مسبق جديد" })], 1));
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" }));

    const link = await screen.findByRole("link", { name: /حجز مسبق جديد/ });
    expect(link).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(link).toHaveTextContent("حجز مسبق #1001 من مكتبة الأمل");
  });

  it("D81 an expired order links to the order", async () => {
    stubApi(shellRoutes([notification({ id: 4, type: "order_expired", type_label: "إلغاء تلقائي لطلب غير مؤكد" })], 1));
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" }));

    const link = await screen.findByRole("link", { name: /إلغاء تلقائي لطلب غير مؤكد/ });
    expect(link).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(link).toHaveTextContent("طلب #1001 من مكتبة الأمل");
  });

  it("marks everything read and refreshes the count", async () => {
    const fetchMock = stubApi({
      ...shellRoutes(),
      "GET /api/dashboard/notifications": [
        json(200, {
          data: [notification()],
          links: {},
          meta: { current_page: 1, last_page: 1, total: 1 },
          unread_count: 1,
        }),
        json(200, { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 }, unread_count: 0 }),
      ],
      "POST /api/dashboard/notifications/read": json(200, { data: { marked: 1 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" }));
    await user.click(await screen.findByRole("button", { name: "تحديد الكل كمقروء" }));

    expect(callsTo(fetchMock, "POST /api/dashboard/notifications/read")[0]?.body).toEqual({ all: true });
    expect(await screen.findByRole("button", { name: "الإشعارات" })).toBeInTheDocument();
  });

  it("marks one notification read when it is opened", async () => {
    const fetchMock = stubApi({
      ...shellRoutes([notification({ id: 5 })], 1),
      "POST /api/dashboard/notifications/read": json(200, { data: { marked: 1 } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<DashboardShell>{null}</DashboardShell>);

    await user.click(await screen.findByRole("button", { name: "الإشعارات، 1 غير مقروءة" }));
    await user.click(await screen.findByRole("link", { name: /طلب #1001/ }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/dashboard/notifications/read")[0]?.body).toEqual({ ids: [5] }),
    );
  });
});
