import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Statement } from "@/components/dashboard/reports/ProfitAndLossTab";
import type { ProfitAndLossFigures, ProfitAndLossReport } from "@/lib/api/types";

import { renderWithQuery } from "../../render";

function figures(overrides: Partial<ProfitAndLossFigures> = {}): ProfitAndLossFigures {
  return {
    from: "2026-10-08",
    to: "2026-10-10",
    net_sales: "10000.00",
    cost_of_goods: "7250.00",
    gross_profit: "2750.00",
    stock_losses: "15.81",
    losses_by_reason: { damaged: "26.31", found: "-10.50" },
    expenses: "2450.50",
    expenses_by_category: { rent: "1500.00", salaries: "600.00", transport: "350.50" },
    net_profit: "283.69",
    net_margin: "2.84",
    uncosted_lines: 0,
    uncosted_adjustments: 0,
    ...overrides,
  };
}

function report(current: Partial<ProfitAndLossFigures> = {}): ProfitAndLossReport {
  return {
    from: "2026-10-08",
    to: "2026-10-10",
    generated_at: "2026-10-10T12:00:00Z",
    current: figures(current),
    previous: figures({ from: "2026-10-05", to: "2026-10-07", net_profit: "100.00", net_margin: "1.00" }),
  };
}

describe("Statement (D100)", () => {
  it("reads top to bottom: net sales, less the cost, the gross, less losses and expenses, the net", () => {
    renderWithQuery(<Statement report={report()} />);

    expect(screen.getByText("صافي المبيعات").parentElement).toHaveTextContent("10,000.00 ج.م");
    expect(screen.getByText("تكلفة البضاعة المباعة").parentElement).toHaveTextContent("− 7,250.00 ج.م");
    expect(screen.getByText("مجمل الربح").parentElement).toHaveTextContent("2,750.00 ج.م");
    expect(screen.getByText("خسائر المخزون").parentElement).toHaveTextContent("− 15.81 ج.م");
    expect(screen.getByText("كسر أو تلف").parentElement).toHaveTextContent("26.31 ج.م");
    expect(screen.getByText("إيجار").parentElement).toHaveTextContent("1,500.00 ج.م");
    expect(screen.getByTestId("net-profit")).toHaveTextContent("283.69 ج.م");
    expect(screen.getByText("هامش صافي الربح").parentElement).toHaveTextContent("2.84%");
    expect(screen.queryByTestId("pl-missing")).toBeNull();
  });

  it("D100 h shows a loss as one, in red", () => {
    renderWithQuery(<Statement report={report({ net_profit: "-1216.31", net_margin: "-12.16" })} />);

    expect(screen.getByText("صافي الربح (خسارة)")).toBeInTheDocument();
    expect(screen.getByTestId("net-profit")).toHaveTextContent("-1,216.31 ج.م");
    expect(screen.getByTestId("net-profit")).toHaveClass("text-destructive");
  });

  it("D96 f D100 g says why a figure is unknown and links to the costs", () => {
    renderWithQuery(
      <Statement
        report={report({ stock_losses: null, net_profit: null, net_margin: null, uncosted_adjustments: 2, uncosted_lines: 3 })}
      />,
    );

    expect(screen.getByTestId("net-profit")).toHaveTextContent("غير مكتمل");
    expect(screen.getByText("خسائر المخزون").parentElement).toHaveTextContent("غير مكتمل");
    expect(screen.getByTestId("pl-missing")).toHaveTextContent("3 سطر مباع بدون تكلفة · 2 تسوية مخزون بدون تكلفة");
    expect(screen.getByRole("link", { name: "حدد التكاليف من المخزون" })).toHaveAttribute("href", "/dashboard/inventory");
  });
});
