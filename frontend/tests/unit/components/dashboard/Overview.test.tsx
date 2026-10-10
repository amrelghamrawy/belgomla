import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Overview } from "@/components/dashboard/overview/Overview";

import { overviewReport } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

describe("Overview", () => {
  it("D5 shows today's orders and sales, what needs attention and orders per status", async () => {
    stubApi({
      "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }),
      "GET /api/dashboard/orders": json(200, page([])),
      "GET /api/dashboard/reports/low-stock": json(200, page([])),
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
    });

    renderWithQuery(<Overview />);

    expect(await screen.findByRole("alert")).toHaveTextContent("حدث خطأ غير متوقع");
    expect(screen.getByRole("button", { name: "حاول مرة أخرى" })).toBeInTheDocument();
  });
});
