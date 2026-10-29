import { fireEvent, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { compact } from "@/components/dashboard/reports/SalesChart";
import {
  BundlesSection,
  LowStockSection,
  OutstandingSection,
  SalesSection,
} from "@/components/dashboard/reports/ReportsPage";

import { page } from "../../fixtures";
import { callsTo, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

const sales = {
  from: "2026-10-01",
  to: "2026-10-03",
  group: "day",
  buckets: [
    { period_start: "2026-10-01", orders: 2, gross: "900.00", returns: "0.00", net: "900.00" },
    { period_start: "2026-10-02", orders: 0, gross: "0.00", returns: "100.00", net: "-100.00" },
    { period_start: "2026-10-03", orders: 1, gross: "350.50", returns: "0.00", net: "350.50" },
  ],
  totals: { orders: 3, gross: "1250.50", returns: "100.00", net: "1150.50" },
};

describe("SalesSection", () => {
  it("D5 BR-R05 shows the net and returns totals and the same buckets as a table", async () => {
    stubApi({ "GET /api/dashboard/reports/sales": json(200, { data: sales }) });
    const user = userEvent.setup();
    renderWithQuery(<SalesSection query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("sales-total")).toHaveTextContent("1,150.50 ج.م");
    expect(screen.getByTestId("returns-total")).toHaveTextContent("100.00 ج.م");
    expect(screen.getByRole("figure", { name: /رسم بياني للمبيعات/ })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "عرض كجدول" }));

    const rows = screen.getAllByRole("row").slice(1);
    expect(rows).toHaveLength(3);
    expect(within(rows[2]!).getAllByText("350.50 ج.م")).toHaveLength(2);
    expect(within(rows[1]!).getByText("100.00 ج.م")).toBeInTheDocument();
  });

  it("sends the range and grouping and changes the grouping through the URL", async () => {
    const fetchMock = stubApi({ "GET /api/dashboard/reports/sales": json(200, { data: { ...sales, group: "week" } }) });
    const setQuery = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(
      <SalesSection query={{ from: "2026-09-01", to: "2026-09-30", group: "week" }} setQuery={setQuery} />,
    );

    await screen.findByTestId("sales-total");
    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
    await user.selectOptions(screen.getByLabelText("التجميع"), "month");

    const url = callsTo(fetchMock, "GET /api/dashboard/reports/sales")[0]?.url;
    expect(Object.fromEntries(url?.searchParams ?? [])).toEqual({
      from: "2026-09-01",
      to: "2026-09-30",
      group: "week",
    });
    expect(setQuery).toHaveBeenCalledWith({ group: "month" });
  });

  it("says when the period has no sales", async () => {
    stubApi({
      "GET /api/dashboard/reports/sales": json(200, {
        data: { ...sales, buckets: [], totals: { orders: 0, gross: "0.00", returns: "0.00", net: "0.00" } },
      }),
    });

    renderWithQuery(<SalesSection query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByText("لا توجد مبيعات في هذه الفترة")).toBeInTheDocument();
  });

  it("compacts axis ticks", () => {
    expect([compact(950), compact(1200), compact(3_400_000)]).toEqual(["950", "1.2K", "3.4M"]);
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

describe("LowStockSection", () => {
  it("D36 lists SKUs at or below their threshold with a link to their stock", async () => {
    stubApi({
      "GET /api/dashboard/reports/low-stock": json(
        200,
        page([
          {
            sku_id: 5,
            product_id: 3,
            product_name: "الأضواء علوم",
            code: "ADW",
            name: null,
            available: 40,
            moq: 5,
            conversion_factor: 20,
            threshold: 100,
          },
        ]),
      ),
    });

    renderWithQuery(<LowStockSection page={1} onPage={vi.fn()} />);

    expect(await screen.findByRole("link", { name: "الأضواء علوم" })).toHaveAttribute("href", "/dashboard/inventory/5");
  });
});

describe("BundlesSection (BR-P15, D93)", () => {
  it("lists the bundles sold and the component units they used over the page's date range", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/bundles": json(200, {
        data: {
          from: "2026-10-01",
          to: "2026-10-31",
          bundles: [{ product_id: 30, name: "طقم أولى إعدادي", sku_code: "SET-1", quantity: 4, total: "540.00" }],
          components: [{ sku_id: 5, sku_code: "ADW-SCI-1", product_name: "الأضواء علوم", quantity_base: 80 }],
        },
      }),
    });
    const setQuery = vi.fn();
    renderWithQuery(<BundlesSection query={{ from: "2026-10-01", to: "2026-10-31" }} setQuery={setQuery} />);

    const bundles = await screen.findByTestId("bundle-report");
    expect(within(bundles).getByRole("link", { name: "طقم أولى إعدادي" })).toHaveAttribute(
      "href",
      "/dashboard/bundles/30",
    );
    expect(bundles).toHaveTextContent("540.00 ج.م");
    expect(screen.getByTestId("bundle-components-report")).toHaveTextContent("الأضواء علوم");
    expect(screen.getByTestId("bundle-components-report")).toHaveTextContent("80");
    expect(callsTo(fetchMock, "GET /api/dashboard/reports/bundles")[0]?.url.search).toBe(
      "?from=2026-10-01&to=2026-10-31",
    );

    fireEvent.click(screen.getByRole("button", { name: /^تصفية/ }));
    fireEvent.change(screen.getByLabelText("من تاريخ"), { target: { value: "2026-10-05" } });
    expect(setQuery).toHaveBeenCalledWith({ from: "2026-10-05" });
  });

  it("says when no bundle was delivered", async () => {
    stubApi({
      "GET /api/dashboard/reports/bundles": json(200, {
        data: { from: "2026-10-01", to: "2026-10-31", bundles: [], components: [] },
      }),
    });
    renderWithQuery(<BundlesSection query={{}} setQuery={vi.fn()} />);

    expect((await screen.findAllByText("لم تُسلَّم باقات في هذه الفترة")).length).toBe(2);
  });
});
