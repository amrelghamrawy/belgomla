import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { compact } from "@/components/dashboard/reports/SalesChart";
import { LowStockSection, SalesSection } from "@/components/dashboard/reports/ReportsPage";

import { page } from "../../fixtures";
import { callsTo, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

const sales = {
  from: "2026-10-01",
  to: "2026-10-03",
  group: "day",
  buckets: [
    { period_start: "2026-10-01", orders: 2, total: "900.00" },
    { period_start: "2026-10-02", orders: 0, total: "0.00" },
    { period_start: "2026-10-03", orders: 1, total: "350.50" },
  ],
  totals: { orders: 3, total: "1250.50" },
};

describe("SalesSection", () => {
  it("D5 shows the totals and the same buckets as a table", async () => {
    stubApi({ "GET /api/dashboard/reports/sales": json(200, { data: sales }) });
    const user = userEvent.setup();
    renderWithQuery(<SalesSection query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByTestId("sales-total")).toHaveTextContent("1,250.50 ج.م");
    expect(screen.getByRole("figure", { name: /رسم بياني للمبيعات/ })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "عرض كجدول" }));

    const rows = screen.getAllByRole("row").slice(1);
    expect(rows).toHaveLength(3);
    expect(within(rows[2]!).getByText("350.50 ج.م")).toBeInTheDocument();
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
        data: { ...sales, buckets: [], totals: { orders: 0, total: "0.00" } },
      }),
    });

    renderWithQuery(<SalesSection query={{}} setQuery={vi.fn()} />);

    expect(await screen.findByText("لا توجد مبيعات في هذه الفترة")).toBeInTheDocument();
  });

  it("compacts axis ticks", () => {
    expect([compact(950), compact(1200), compact(3_400_000)]).toEqual(["950", "1.2K", "3.4M"]);
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
