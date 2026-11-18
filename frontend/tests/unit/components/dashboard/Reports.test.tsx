import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AgingBar, CollectionsTab, OutstandingSection } from "@/components/dashboard/reports/CollectionsTab";
import { CustomersTab } from "@/components/dashboard/reports/CustomersTab";
import { InventoryTab, LowStockSection } from "@/components/dashboard/reports/InventoryTab";
import { ProductsTab } from "@/components/dashboard/reports/ProductsTab";
import { ReportsPage } from "@/components/dashboard/reports/ReportsPage";
import { compact } from "@/components/dashboard/reports/SalesChart";
import { ProfitTiles, SalesTab } from "@/components/dashboard/reports/SalesTab";
import { saveDownload } from "@/lib/api/download";
import { cairoToday, presetRange } from "@/lib/dashboard/report-range";

import { category, overviewReport, signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, json, stubApi } from "../../http";
import { renderWithQuery as renderBare, pathname, router, searchParams } from "../../render";

vi.mock("@/lib/api/download", () => ({ saveDownload: vi.fn() }));

/** The report filters follow the role (D82): render for a signed-in owner unless a test picks a role. */
function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

beforeEach(() => {
  vi.mocked(saveDownload).mockReset();
  router.replace.mockClear();
});

const GENERATED = "2026-10-29T08:05:00Z";

/** The pickers the toolbar loads: the category tree and the customer groups. */
const pickers = {
  "GET /api/dashboard/categories": json(200, {
    data: [category({ id: 4, name: "الكتب الخارجية", children: [category({ id: 9, name: "ثانوي", parent_id: 4 })] })],
  }),
  "GET /api/dashboard/customer-groups": json(200, {
    data: [
      { id: 2, name: "تجار الجملة", discount_percent: "5.00", status: "active", sort_order: 0, customers_count: 3 },
    ],
  }),
};

const figures = (orders: number, gross: string, discounts: string, returns: string, net: string) => ({
  orders,
  gross,
  discounts,
  returns,
  net,
});

const salesAnalytics = {
  from: "2026-10-01",
  to: "2026-10-03",
  group: "day",
  generated_at: GENERATED,
  buckets: [
    { period_start: "2026-10-01", ...figures(2, "1000.00", "100.00", "0.00", "900.00") },
    { period_start: "2026-10-02", ...figures(0, "0.00", "0.00", "100.00", "-100.00") },
    { period_start: "2026-10-03", ...figures(1, "400.00", "49.50", "0.00", "350.50") },
  ],
  totals: { ...figures(3, "1400.00", "149.50", "100.00", "1150.50"), average_order: "416.83" },
  profit: { cost: "800.00", profit: "350.50", margin: "30.46", uncosted_lines: 0 },
  previous: {
    from: "2026-09-28",
    to: "2026-09-30",
    totals: { ...figures(2, "1000.00", "0.00", "0.00", "1000.00"), average_order: "500.00" },
    buckets: [
      { period_start: "2026-09-28", net: "600.00" },
      { period_start: "2026-09-29", net: "0.00" },
      { period_start: "2026-09-30", net: "400.00" },
    ],
  },
  by_section: [{ kind: "books", label: "كتب", ...figures(3, "1400.00", "149.50", "100.00", "1150.50") }],
  by_customer_group: [
    { customer_group_id: 2, name: "تجار الجملة", ...figures(2, "1000.00", "100.00", "0.00", "900.00") },
    { customer_group_id: null, name: null, ...figures(1, "400.00", "49.50", "100.00", "250.50") },
  ],
};

describe("ReportsPage (V2 Task 16)", () => {
  it("opens on the overview tab with the V1 cards and offers the six reports as tabs", async () => {
    searchParams.value = new URLSearchParams();
    stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, {
        data: {
          from: "2026-09-30",
          to: "2026-10-29",
          group: "day",
          buckets: [],
          totals: { orders: 0, gross: "0.00", returns: "0.00", net: "0.00" },
        },
      }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<ReportsPage />);

    expect(screen.getByRole("heading", { level: 1, name: "التقارير" })).toBeInTheDocument();
    expect(screen.getAllByRole("tab").map((tab) => tab.textContent)).toEqual([
      "نظرة عامة",
      "المبيعات",
      "المنتجات",
      "العملاء",
      "المخزون",
      "التحصيل",
      "الأرباح والخسائر",
    ]);
    expect(screen.getByRole("tab", { name: "نظرة عامة" })).toHaveAttribute("aria-selected", "true");
    expect(await screen.findByRole("link", { name: /طلبات اليوم/ })).toBeInTheDocument();
  });

  it("keeps the tab in the URL", async () => {
    searchParams.value = new URLSearchParams("tab=collections");
    pathname.value = "/dashboard/reports";
    stubApi({
      "GET /api/dashboard/reports/collections": json(200, { data: collections() }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
      ...pickers,
    });
    const user = userEvent.setup();

    renderWithQuery(<ReportsPage />);

    expect(screen.getByRole("tab", { name: "التحصيل" })).toHaveAttribute("aria-selected", "true");
    expect(await screen.findByTestId("collected-net")).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "المبيعات" }));
    expect(router.replace).toHaveBeenCalledWith("/dashboard/reports?tab=sales", { scroll: false });
  });
});

describe("SalesTab", () => {
  it("D94 shows the totals against the previous period, «آخر تحديث», the splits and the buckets", async () => {
    stubApi({ "GET /api/dashboard/reports/sales-analytics": json(200, { data: salesAnalytics }), ...pickers });

    renderWithQuery(<SalesTab query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("sales-net")).toHaveTextContent("1,150.50 ج.م");
    expect(screen.getByTestId("sales-net-delta")).toHaveTextContent("+15.1% عن الفترة السابقة");
    expect(screen.getByTestId("sales-discounts")).toHaveTextContent("149.50 ج.م");
    expect(screen.queryByTestId("sales-discounts-delta")).not.toBeInTheDocument();
    expect(screen.getByTestId("sales-orders-delta")).toHaveTextContent("+50%");
    expect(screen.getByTestId("sales-average-delta")).toHaveTextContent("−16.6%");
    expect(screen.getByTestId("generated-at")).toHaveTextContent(/^آخر تحديث: 29 أكتوبر 2026/);
    expect(screen.getByRole("figure", { name: /رسم بياني لصافي المبيعات/ })).toBeInTheDocument();
    expect(screen.getByText("الفترة السابقة")).toBeInTheDocument();

    const buckets = within(screen.getByTestId("sales-buckets")).getAllByRole("row").slice(1);
    expect(buckets).toHaveLength(3);
    expect(buckets[1]).toHaveTextContent("-100.00 ج.م");
    expect(screen.getByTestId("sales-by-section")).toHaveTextContent("كتب");
    expect(within(screen.getByTestId("sales-by-group")).getAllByRole("row")[2]).toHaveTextContent("بدون مجموعة");
  });

  it("sends only the filters the report reads and changes them through the URL", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/sales-analytics": json(200, { data: salesAnalytics }),
      ...pickers,
    });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(
      <SalesTab
        query={{ tab: "sales", from: "2026-09-01", to: "2026-09-30", group: "week", view: "dormant", dead_only: "1" }}
        setQuery={setQuery}
      />,
    );

    await screen.findByTestId("sales-net");
    expect(
      Object.fromEntries(callsTo(fetchMock, "GET /api/dashboard/reports/sales-analytics")[0]?.url.searchParams ?? []),
    ).toEqual({ from: "2026-09-01", to: "2026-09-30", group: "week" });

    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    await user.selectOptions(screen.getByLabelText("التجميع"), "month");
    expect(setQuery).toHaveBeenCalledWith({ group: "month" });
    await user.selectOptions(await screen.findByLabelText("القسم"), "9");
    expect(setQuery).toHaveBeenCalledWith({ category_id: "9" });
    expect(within(screen.getByLabelText("القسم")).getByRole("option", { name: "— ثانوي" })).toBeInTheDocument();
    await user.selectOptions(await screen.findByLabelText("مجموعة العملاء"), "2");
    expect(setQuery).toHaveBeenCalledWith({ customer_group_id: "2" });
    fireEvent.change(screen.getByLabelText("من تاريخ"), { target: { value: "2026-09-05" } });
    expect(setQuery).toHaveBeenCalledWith({ from: "2026-09-05" });
  });

  it("D58 sets the range from a preset: the week from Saturday, the month, the season from 1 August", async () => {
    stubApi({ "GET /api/dashboard/reports/sales-analytics": json(200, { data: salesAnalytics }), ...pickers });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    const today = cairoToday();
    renderWithQuery(<SalesTab query={presetRange("month", today)} setQuery={setQuery} />);

    await screen.findByTestId("sales-net");
    expect(screen.getByRole("button", { name: "هذا الشهر" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "هذا الأسبوع" })).toHaveAttribute("aria-pressed", "false");
    await user.click(screen.getByRole("button", { name: "هذا الأسبوع" }));
    expect(setQuery).toHaveBeenCalledWith(presetRange("week", today));
    await user.click(screen.getByRole("button", { name: "هذا الموسم" }));
    expect(setQuery).toHaveBeenCalledWith(presetRange("season", today));
  });

  it("D94 k exports every row with the same filters as Excel or CSV", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/sales-analytics": json(200, { data: salesAnalytics }),
      "GET /api/dashboard/reports/sales-analytics/export": new Response("x", {
        headers: { "Content-Disposition": "attachment; filename=report-sales-analytics-2026-09-01-2026-09-30.xlsx" },
      }),
      ...pickers,
    });
    renderWithQuery(
      <SalesTab query={{ from: "2026-09-01", to: "2026-09-30", category_id: "4", page: "2" }} setQuery={vi.fn()} />,
    );

    await screen.findByTestId("sales-net");
    fireEvent.click(screen.getByRole("button", { name: "تصدير" }));

    await waitFor(() =>
      expect(saveDownload).toHaveBeenCalledWith(
        expect.objectContaining({ fileName: "report-sales-analytics-2026-09-01-2026-09-30.xlsx" }),
      ),
    );
    expect(
      Object.fromEntries(
        callsTo(fetchMock, "GET /api/dashboard/reports/sales-analytics/export")[0]?.url.searchParams ?? [],
      ),
    ).toEqual({ from: "2026-09-01", to: "2026-09-30", category_id: "4", format: "xlsx" });

    fireEvent.click(screen.getByRole("button", { name: "ملف CSV" }));
    await waitFor(() =>
      expect(
        callsTo(fetchMock, "GET /api/dashboard/reports/sales-analytics/export")[1]?.url.searchParams.get("format"),
      ).toBe("csv"),
    );
  });

  it("D82 hides the customer group filter from a role that may not see the groups", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/sales-analytics": json(200, { data: salesAnalytics }),
      ...pickers,
    });
    const user = userEvent.setup();
    renderWithQuery(<SalesTab query={{}} setQuery={vi.fn()} />, signedInAs("accountant"));

    await screen.findByTestId("sales-net");
    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    expect(await screen.findByLabelText("القسم")).toBeInTheDocument();
    expect(screen.queryByLabelText("مجموعة العملاء")).not.toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/dashboard/customer-groups")).toHaveLength(0);
  });

  it("says when neither period had sales instead of an empty chart", async () => {
    const zero = { ...figures(0, "0.00", "0.00", "0.00", "0.00"), average_order: null };
    stubApi({
      "GET /api/dashboard/reports/sales-analytics": json(200, {
        data: {
          ...salesAnalytics,
          buckets: [],
          totals: zero,
          previous: { ...salesAnalytics.previous, totals: zero, buckets: [] },
          by_section: [],
          by_customer_group: [],
        },
      }),
      ...pickers,
    });

    renderWithQuery(<SalesTab query={{}} setQuery={vi.fn()} />);

    expect((await screen.findAllByText("لا توجد مبيعات في هذه الفترة")).length).toBe(3);
    expect(screen.getByTestId("sales-average")).toHaveTextContent("—");
  });

  it("D98 shows the cost of what was sold, the profit and its margin", () => {
    renderBare(<ProfitTiles report={salesAnalytics as never} />);

    expect(screen.getByTestId("sales-cost")).toHaveTextContent("800.00 ج.م");
    expect(screen.getByTestId("sales-profit")).toHaveTextContent("350.50 ج.م");
    expect(screen.getByTestId("sales-margin")).toHaveTextContent("30.46%");
    expect(screen.queryByTestId("uncosted-hint")).toBeNull();
  });

  it("D96 f D98 says the profit is incomplete and how many lines lack a cost, never a partial figure", () => {
    renderBare(
      <ProfitTiles
        report={{ ...salesAnalytics, profit: { cost: "500.00", profit: null, margin: null, uncosted_lines: 4 } } as never}
      />,
    );

    expect(screen.getByTestId("sales-profit")).toHaveTextContent("غير مكتمل");
    expect(screen.getByTestId("sales-margin")).toHaveTextContent("—");
    expect(screen.getByTestId("uncosted-hint")).toHaveTextContent("4 سطر مباع بدون تكلفة");
  });

  it("compacts axis ticks", () => {
    expect([compact(950), compact(1200), compact(3_400_000)]).toEqual(["950", "1.2K", "3.4M"]);
  });
});

const productRow = (overrides: Record<string, unknown> = {}) => ({
  sku_id: 5,
  product_id: 3,
  product_name: "الأضواء علوم",
  sku_code: "ADW-SCI-1",
  sku_name: null,
  is_bundle: false,
  quantity: 4,
  quantity_base: 120,
  bundle_quantity_base: 40,
  revenue: "2400.00",
  returns: "60.00",
  returned_base: 3,
  return_rate: "2.50",
  customers: 3,
  last_sold_at: "2026-10-20T10:00:00Z",
  cost: "1600.00",
  profit: "800.00",
  margin: "33.33",
  uncosted_lines: 0,
  ...overrides,
});

const bundleReport = {
  from: "2026-10-01",
  to: "2026-10-31",
  bundles: [{ product_id: 30, name: "طقم أولى إعدادي", sku_code: "SET-1", quantity: 4, total: "540.00" }],
  components: [{ sku_id: 5, sku_code: "ADW-SCI-1", product_name: "الأضواء علوم", quantity_base: 80 }],
};

describe("ProductsTab", () => {
  it("D93 bars the top 20, lists every SKU with bundles badged and the units inside bundles, and keeps the bundles", async () => {
    const productsPage = {
      ...page([
        productRow(),
        productRow({
          sku_id: 31,
          product_id: 30,
          product_name: "طقم أولى إعدادي",
          sku_code: "SET-1",
          is_bundle: true,
          quantity_base: 4,
          bundle_quantity_base: 0,
          revenue: "540.00",
          return_rate: null,
        }),
      ]),
      from: "2026-10-01",
      to: "2026-10-31",
      sort: "revenue",
      generated_at: GENERATED,
    };
    stubApi({
      "GET /api/dashboard/reports/products": json(200, productsPage),
      "GET /api/dashboard/reports/bundles": json(200, { data: bundleReport }),
      ...pickers,
    });

    renderWithQuery(<ProductsTab query={{}} setQuery={vi.fn()} />);

    const table = await screen.findByTestId("products-report");
    const [, single, bundle] = within(table).getAllByRole("row");
    expect(single).toHaveTextContent("2.5%");
    expect(within(single!).getByTestId("in-bundles")).toHaveTextContent("منها داخل باقات: 40");
    expect(within(bundle!).getByText("باقة")).toBeInTheDocument();
    expect(within(bundle!).getByRole("link", { name: "طقم أولى إعدادي" })).toHaveAttribute(
      "href",
      "/dashboard/bundles/30",
    );
    expect(bundle).toHaveTextContent("—");
    expect(within(screen.getByTestId("top-products")).getAllByRole("link")[0]).toHaveTextContent(
      "الأضواء علوم2,400.00 ج.م",
    );
    expect(screen.getByTestId("generated-at")).toBeInTheDocument();
    expect(await screen.findByTestId("bundle-report")).toHaveTextContent("540.00 ج.م");
    expect(screen.getByTestId("bundle-components-report")).toHaveTextContent("80");
  });

  it("asks for the page and the order, and changes the order through the URL", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/products": json(200, {
        ...page([productRow()], { current_page: 2, last_page: 3, per_page: 20, total: 41 }),
        from: "2026-10-01",
        to: "2026-10-31",
        sort: "quantity",
        generated_at: GENERATED,
      }),
      "GET /api/dashboard/reports/bundles": json(200, { data: bundleReport }),
      ...pickers,
    });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<ProductsTab query={{ sort: "quantity", page: "2" }} setQuery={setQuery} />);

    await screen.findByTestId("products-report");
    const calls = callsTo(fetchMock, "GET /api/dashboard/reports/products").map((call) =>
      Object.fromEntries(call.url.searchParams),
    );
    expect(calls).toContainEqual({ per_page: "20", sort: "quantity" });
    expect(calls).toContainEqual({ per_page: "20", sort: "quantity", page: "2" });

    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    await user.selectOptions(screen.getByLabelText("الترتيب"), "return_rate");
    expect(setQuery).toHaveBeenCalledWith({ sort: "return_rate" });
    await user.click(screen.getByRole("button", { name: /السابق/ }));
    expect(setQuery).toHaveBeenCalledWith({ page: 1 });
  });
});

const customerRow = (overrides: Record<string, unknown> = {}) => ({
  customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
  customer_group: { id: 2, name: "تجار الجملة" },
  orders: 0,
  revenue: "0.00",
  average_order: null,
  first_order_at: "2026-05-01T10:00:00Z",
  last_order_at: "2026-08-01T10:00:00Z",
  lifetime_orders: 3,
  average_interval_days: 46,
  days_since_last_order: 89,
  dormant: true,
  outstanding: "800.00",
  overdue: "500.00",
  ...overrides,
});

describe("CustomersTab", () => {
  it("D94 h lists the dormant customers with their badge, debts and a link to each", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/customers": json(200, {
        ...page([customerRow()]),
        from: "2026-10-01",
        to: "2026-10-31",
        view: "dormant",
        dormant_days: 30,
        generated_at: GENERATED,
      }),
      ...pickers,
    });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<CustomersTab query={{ view: "dormant", dormant_days: "30" }} setQuery={setQuery} />);

    const link = await screen.findByRole("link", { name: "مكتبة الأمل" });
    expect(link).toHaveAttribute("href", "/dashboard/customers/7");
    const row = link.closest("tr") as HTMLElement;
    expect(within(row).getByTestId("dormant")).toHaveTextContent("خامل");
    expect(row).toHaveTextContent("800.00 ج.م");
    expect(row).toHaveTextContent("تجار الجملة");
    expect(screen.getByRole("button", { name: "العملاء الخاملون" })).toHaveAttribute("aria-pressed", "true");
    expect(
      Object.fromEntries(callsTo(fetchMock, "GET /api/dashboard/reports/customers")[0]?.url.searchParams ?? []),
    ).toEqual({ per_page: "20", view: "dormant", dormant_days: "30" });

    await user.click(screen.getByRole("button", { name: "أفضل العملاء" }));
    expect(setQuery).toHaveBeenCalledWith({ view: null });
    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    fireEvent.change(screen.getByLabelText("خامل بعد (يوم)"), { target: { value: "60" } });
    expect(setQuery).toHaveBeenCalledWith({ dormant_days: "60" });
    fireEvent.change(screen.getByLabelText("خامل بعد (يوم)"), { target: { value: "" } });
    expect(setQuery).toHaveBeenCalledWith({ dormant_days: null });
  });

  it("says when the view has nobody", async () => {
    stubApi({
      "GET /api/dashboard/reports/customers": json(200, {
        ...page([]),
        from: "2026-10-01",
        to: "2026-10-31",
        view: "top",
        dormant_days: 45,
        generated_at: GENERATED,
      }),
      ...pickers,
    });

    renderWithQuery(<CustomersTab query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByText("لا يوجد عملاء في هذا العرض")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "أفضل العملاء" })).toHaveAttribute("aria-pressed", "true");
  });
});

const inventoryRow = (overrides: Record<string, unknown> = {}) => ({
  sku_id: 5,
  product_id: 3,
  product_name: "الأضواء علوم",
  sku_code: "ADW-SCI-1",
  sku_name: null,
  on_hand: 200,
  available: 180,
  stock_value: "6000.00",
  sold_base: 0,
  days_of_cover: null,
  last_sold_at: null,
  dead: true,
  movements: { opening: 0, adjustment: 0, sale: 0, return: 0, receive: 0 },
  ...overrides,
});

const lowStock = {
  sku_id: 6,
  product_id: 4,
  product_name: "سلاح التلميذ رياضيات",
  code: "SLH",
  name: null,
  available: 40,
  moq: 5,
  conversion_factor: 20,
  threshold: 100,
};

describe("InventoryTab", () => {
  it("D94 values the stock at the selling price, shows ∞ cover without sales, badges dead stock, keeps low stock", async () => {
    stubApi({
      "GET /api/dashboard/reports/inventory": json(200, {
        ...page([inventoryRow(), inventoryRow({ sku_id: 8, dead: false, sold_base: 30, days_of_cover: 12 })]),
        from: "2026-10-01",
        to: "2026-10-31",
        dead_days: 60,
        summary: {
          skus: 2,
          on_hand: 400,
          stock_value: "12000.00",
          dead_count: 1,
          dead_value: "6000.00",
          sold_base: 30,
          movements: { opening: 0, adjustment: 5, sale: -30, return: 2, receive: 0 },
        },
        generated_at: GENERATED,
      }),
      "GET /api/dashboard/reports/low-stock": json(200, page([lowStock])),
      ...pickers,
    });

    renderWithQuery(<InventoryTab query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("stock-value")).toHaveTextContent("12,000.00 ج.م");
    expect(screen.getAllByText("قيمة المخزون بسعر البيع").length).toBeGreaterThan(0);
    expect(screen.getByTestId("dead-count")).toHaveTextContent("1");
    expect(screen.getByTestId("movements")).toHaveTextContent("-30");
    const [first, second] = screen.getAllByTestId("cover");
    expect(first).toHaveTextContent("∞");
    expect(second).toHaveTextContent("12");
    expect(screen.getAllByTestId("dead")).toHaveLength(1);
    expect(await screen.findByRole("link", { name: "سلاح التلميذ رياضيات" })).toHaveAttribute(
      "href",
      "/dashboard/inventory/6",
    );
  });

  it("filters to dead stock and its threshold through the URL", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/inventory": json(200, {
        ...page([]),
        from: "2026-10-01",
        to: "2026-10-31",
        dead_days: 90,
        summary: {
          skus: 0,
          on_hand: 0,
          stock_value: "0.00",
          dead_count: 0,
          dead_value: "0.00",
          sold_base: 0,
          movements: { opening: 0, adjustment: 0, sale: 0, return: 0, receive: 0 },
        },
        generated_at: GENERATED,
      }),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      ...pickers,
    });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<InventoryTab query={{ dead_days: "90", customer_group_id: "2" }} setQuery={setQuery} />);

    expect(await screen.findByText("لا توجد أصناف")).toBeInTheDocument();
    expect(
      Object.fromEntries(callsTo(fetchMock, "GET /api/dashboard/reports/inventory")[0]?.url.searchParams ?? []),
    ).toEqual({ per_page: "20", dead_days: "90" });
    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    await user.click(screen.getByRole("checkbox", { name: "الراكد فقط" }));
    expect(setQuery).toHaveBeenCalledWith({ dead_only: "1" });
    expect(screen.queryByLabelText("مجموعة العملاء")).not.toBeInTheDocument();
  });
});

describe("LowStockSection", () => {
  it("D36 lists SKUs at or below their threshold with a link to their stock", async () => {
    stubApi({
      "GET /api/dashboard/reports/low-stock": json(
        200,
        page([{ ...lowStock, sku_id: 5, product_name: "الأضواء علوم" }]),
      ),
    });

    renderWithQuery(<LowStockSection page={1} onPage={vi.fn()} />);

    expect(await screen.findByRole("link", { name: "الأضواء علوم" })).toHaveAttribute("href", "/dashboard/inventory/5");
  });
});

function collections(overrides: Record<string, unknown> = {}) {
  return {
    from: "2026-10-01",
    to: "2026-10-02",
    group: "day",
    generated_at: GENERATED,
    buckets: [
      {
        period_start: "2026-10-01",
        cash: "500.00",
        credit: "100.00",
        refunds: "-50.00",
        net: "550.00",
        delivered: "900.00",
      },
      { period_start: "2026-10-02", cash: "0.00", credit: "0.00", refunds: "0.00", net: "0.00", delivered: "200.00" },
    ],
    totals: {
      cash: "500.00",
      credit: "100.00",
      refunds: "-50.00",
      net: "550.00",
      delivered: "1100.00",
      collection_rate: "50.00",
    },
    aging: {
      current: "300.00",
      "0_30": "0.00",
      "31_60": "500.00",
      "61_90": "0.00",
      over_90: "200.00",
      overdue: "700.00",
      outstanding: "1000.00",
    },
    ...overrides,
  };
}

describe("CollectionsTab", () => {
  it("D94 j shows the net collected, the collection rate, the aging bar, the buckets and who owes", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/collections": json(200, { data: collections() }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
      ...pickers,
    });

    renderWithQuery(<CollectionsTab query={{ group: "week", category_id: "4" }} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("collected-net")).toHaveTextContent("550.00 ج.م");
    expect(screen.getByTestId("collected-net")).toBeInTheDocument();
    expect(screen.getByTestId("collection-rate")).toHaveTextContent("50%");
    expect(screen.getByTestId("collected-delivered")).toHaveTextContent("1,100.00 ج.م");
    expect(screen.getByTestId("aging-outstanding")).toHaveTextContent("1,000.00 ج.م");
    expect(within(screen.getByTestId("collections-buckets")).getAllByRole("row")[1]).toHaveTextContent("-50.00 ج.م");
    expect(screen.getByTestId("generated-at")).toBeInTheDocument();
    expect(await screen.findByText("لا توجد مستحقات")).toBeInTheDocument();
    expect(
      Object.fromEntries(callsTo(fetchMock, "GET /api/dashboard/reports/collections")[0]?.url.searchParams ?? []),
    ).toEqual({ group: "week" });
  });

  it("has no rate when nothing net was delivered", async () => {
    stubApi({
      "GET /api/dashboard/reports/collections": json(200, {
        data: collections({
          totals: {
            cash: "0.00",
            credit: "0.00",
            refunds: "0.00",
            net: "0.00",
            delivered: "0.00",
            collection_rate: null,
          },
        }),
      }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
      ...pickers,
    });

    renderWithQuery(<CollectionsTab query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("collection-rate")).toHaveTextContent("—");
    expect(screen.getByText("لا يوجد تحصيل ولا مبيعات في هذه الفترة")).toBeInTheDocument();
  });
});

describe("AgingBar", () => {
  it("BR-C06 stacks the buckets with debt into one bar sized by their share and lists every amount", () => {
    renderBare(<AgingBar aging={collections().aging} />);

    expect(screen.getByTestId("aging-current")).toHaveStyle({ width: "30%" });
    expect(screen.getByTestId("aging-31_60")).toHaveStyle({ width: "50%" });
    expect(screen.getByTestId("aging-over_90")).toHaveStyle({ width: "20%" });
    expect(screen.queryByTestId("aging-0_30")).not.toBeInTheDocument();
    expect(screen.getByRole("figure", { name: /توزيع المستحقات/ })).toHaveTextContent("أكثر من 90200.00 ج.م");
  });

  it("says when nobody owes anything", () => {
    renderBare(
      <AgingBar
        aging={{
          current: "0.00",
          "0_30": "0.00",
          "31_60": "0.00",
          "61_90": "0.00",
          over_90: "0.00",
          overdue: "0.00",
          outstanding: "0.00",
        }}
      />,
    );

    expect(screen.getByText("لا توجد مستحقات الآن")).toBeInTheDocument();
  });
});

describe("OutstandingSection", () => {
  it("BR-C06 shows each customer's debt aged by days past due, with the overdue part", async () => {
    stubApi({
      "GET /api/dashboard/reports/outstanding": json(
        200,
        page([
          {
            customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
            orders: 2,
            total: "900.00",
            collected: "100.00",
            outstanding: "800.00",
            aging: { current: "300.00", "0_30": "0.00", "31_60": "500.00", "61_90": "0.00", over_90: "0.00" },
            overdue: "500.00",
          },
        ]),
      ),
    });

    renderWithQuery(<OutstandingSection page={1} onPage={vi.fn()} />);

    const row = (await screen.findByRole("link", { name: "مكتبة الأمل" })).closest("tr") as HTMLElement;
    expect(row).toHaveTextContent("300.00 ج.م");
    expect(within(row).getByTestId("overdue")).toHaveTextContent("500.00 ج.م");
    expect(screen.getByRole("columnheader", { name: "31–60" })).toBeInTheDocument();
  });
});
