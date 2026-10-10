import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Overview } from "@/components/dashboard/overview/Overview";

import { overviewReport } from "../../dashboard-fixtures";
import { envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

describe("Overview", () => {
  it("D5 shows today's orders and sales, what needs attention and orders per status", async () => {
    stubApi({ "GET /api/dashboard/reports/overview": json(200, { data: overviewReport() }) });

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

  it("shows the error with a retry", async () => {
    stubApi({ "GET /api/dashboard/reports/overview": envelopeError(500, "server_error", "حدث خطأ غير متوقع") });

    renderWithQuery(<Overview />);

    expect(await screen.findByRole("alert")).toHaveTextContent("حدث خطأ غير متوقع");
    expect(screen.getByRole("button", { name: "حاول مرة أخرى" })).toBeInTheDocument();
  });
});
