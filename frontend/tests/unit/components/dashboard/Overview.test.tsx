import { screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { describe, expect, it } from "vitest";

import { Overview } from "@/components/dashboard/overview/Overview";

import { overviewReport, signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery as renderBare } from "../../render";

/** These screens hide what the role may not do (D82): render them for a signed-in owner unless a test picks a role. */
function renderWithQuery(ui: ReactElement, client = signedInAs("owner")) {
  return renderBare(ui, client);
}

const emptySales = {
  from: "2026-09-11",
  to: "2026-10-10",
  group: "day",
  buckets: [],
  totals: { orders: 0, gross: "0.00", returns: "0.00", net: "0.00" },
};

describe("Overview", () => {
  it("D82 a role without reports gets a greeting and no report calls", () => {
    const fetchMock = stubApi({});
    renderWithQuery(<Overview />, signedInAs("sales"));

    expect(screen.getByRole("heading", { name: "أهلًا أحمد" })).toBeInTheDocument();
    expect(screen.getByTestId("welcome")).toHaveTextContent("اختر من القائمة ما تريد العمل عليه.");
    expect(callsTo(fetchMock, "GET /api/dashboard/reports/overview")).toHaveLength(0);
  });

  it("charts the last 30 days of sales and the five largest debts", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, {
        data: {
          ...emptySales,
          buckets: [{ period_start: "2026-10-01", orders: 3, gross: "1500.00", returns: "0.00", net: "1500.00" }],
          totals: { orders: 3, gross: "1500.00", returns: "0.00", net: "1500.00" },
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
    expect(screen.getByRole("link", { name: /متأخر السداد/ })).toHaveTextContent("0 عميل");
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
            collection_status: "unpaid",
            order_total: "100.00",
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

    const stale = await screen.findByTestId("stale-orders");
    expect(await within(stale).findByRole("link", { name: "#1001" })).toHaveAttribute("href", "/dashboard/orders/1001");
    expect(await screen.findByTestId("low-stock")).toHaveTextContent("40 / 100");
    const query = callsTo(fetchMock, "GET /api/dashboard/orders").find((call) => call.url.searchParams.has("stale"))
      ?.url.searchParams;
    expect(query?.get("stale")).toBe("1");
    expect(query?.get("sort")).toBe("placed_at");
  });

  it("D104 leads with the five latest orders: serial, customer, collection copy and total", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(
        200,
        page([
          {
            number: 1002,
            placed_at: "2026-10-01T08:00:00Z",
            customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
            delivery: { name: "x" },
            collection_status: "partially_collected",
            order_total: "8450.00",
          },
        ]),
      ),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    const latest = await screen.findByTestId("latest-orders");
    expect(await within(latest).findByRole("link", { name: "#1002" })).toHaveAttribute("href", "/dashboard/orders/1002");
    expect(latest).toHaveTextContent("مكتبة الأمل");
    expect(within(latest).getByTestId("collection-status")).toHaveTextContent("محصل جزئيًا");
    expect(latest).toHaveTextContent("8,450.00 ج.م");
    const query = callsTo(fetchMock, "GET /api/dashboard/orders").find((call) => !call.url.searchParams.has("stale"))
      ?.url.searchParams;
    expect(query?.get("per_page")).toBe("5");
  });

  it("BR-C06 flags what is overdue and for how many customers", async () => {
    stubApi({
      "GET /api/dashboard/reports/overview": json(200, {
        data: overviewReport({ overdue_total: "750.00", overdue_customers: 2 }),
      }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
      "GET /api/dashboard/reports/sales": json(200, { data: emptySales }),
      "GET /api/dashboard/reports/outstanding": json(200, page([])),
    });

    renderWithQuery(<Overview />);

    const tile = await screen.findByRole("link", { name: /متأخر السداد/ });
    expect(tile).toHaveTextContent("750.00 ج.م");
    expect(tile).toHaveTextContent("2 عميل");
    expect(tile).toHaveAttribute("href", "/dashboard/reports?tab=collections");
    // D104, WCAG 1.4.1: the warning is said in words, not only by colour.
    expect(tile).toHaveTextContent("يحتاج متابعة");
    expect(screen.getByRole("link", { name: /مبيعات اليوم/ })).not.toHaveTextContent("يحتاج متابعة");
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
