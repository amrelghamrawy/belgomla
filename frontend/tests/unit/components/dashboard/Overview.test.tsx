import { screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Overview } from "@/components/dashboard/overview/Overview";

import { overviewReport } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

const emptySales = {
  from: "2026-09-11",
  to: "2026-10-10",
  group: "day",
  buckets: [],
  totals: { orders: 0, total: "0.00" },
};

describe("Overview", () => {
  it("charts the last 30 days of sales and the five largest debts", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, {
        data: {
          ...emptySales,
          buckets: [{ period_start: "2026-10-01", orders: 3, total: "1500.00" }],
          totals: { orders: 3, total: "1500.00" },
        },
      }),
      "GET /api/dashboard/reports/outstanding": json(
        200,
        page(
          [1, 2, 3, 4, 5, 6].map((id) => ({
            customer: { id, name: `عميل ${id}`, phone: "+201012345678" },
            orders: 1,
            total: "500.00",
            collected: "0.00",
            outstanding: `${700 - id * 100}.00`,
          })),
        ),
      ),
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByTestId("sales-trend-total")).toHaveTextContent("1,500.00 ج.م3 طلب مُسلَّم");
    expect(screen.getByRole("figure", { name: /رسم بياني للمبيعات/ })).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/dashboard/reports/sales")[0]?.url.search).toBe("");
    const debtors = within(await screen.findByRole("list", { name: "أكبر المديونيات" })).getAllByRole("link");
    expect(debtors).toHaveLength(5);
    expect(debtors[0]).toHaveTextContent("عميل 1600.00 ج.م");
    expect(debtors[0]).toHaveAttribute("href", "/dashboard/customers/1?tab=statement");
  });

  it("says when there were no delivered sales instead of an empty chart", async () => {
    stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByText("لا توجد مبيعات مسلّمة في آخر 30 يومًا")).toBeInTheDocument();
    expect(screen.queryByRole("figure")).not.toBeInTheDocument();
    expect(await screen.findByText("لا توجد مستحقات")).toBeInTheDocument();
  });
  it("D5 shows today's orders and sales, what needs attention and orders per status", async () => {
    stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByRole("link", { name: /طلبات اليوم\s*4/ })).toHaveAttribute("href", "/dashboard/orders");
    expect(screen.getByRole("link", { name: /مبيعات اليوم/ })).toHaveTextContent("1,250.50 ج.م");
    expect(screen.getByRole("link", { name: /طلبات متأخرة/ })).toHaveAttribute("href", "/dashboard/orders?stale=1");
    expect(screen.getByRole("link", { name: /أصناف قاربت على النفاد/ })).toHaveAttribute(
      "href",
      "/dashboard/inventory?low_stock=1",
    );
    expect(screen.getByTestId("status-delivered")).toHaveTextContent("9");
    expect(screen.getByTestId("status-pending")).toHaveAttribute("href", "/dashboard/orders?status=pending");
  });

  it("D10 lists the stale pending orders oldest first and the low-stock SKUs", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(
        200,
        page([
          {
            number: 1001,
            placed_at: "2026-10-01T08:00:00Z",
            customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
            delivery: { name: "x" },
          },
        ]),
      ),
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
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByRole("link", { name: "#1001" })).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(await screen.findByTestId("low-stock")).toHaveTextContent("40 / 100");
    const query = callsTo(fetchMock, "GET /api/dashboard/orders")[0]?.url.searchParams;
    expect(query?.get("stale")).toBe("1");
    expect(query?.get("sort")).toBe("placed_at");
  });

  it("shows the error with a retry", async () => {
    stubApi({
      "GET /api/dashboard/reports/overview": envelopeError(500, "server_error", "حدث خطأ غير متوقع"),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByRole("alert")).toHaveTextContent("حدث خطأ غير متوقع");
    expect(screen.getByRole("button", { name: "حاول مرة أخرى" })).toBeInTheDocument();
  });
});
