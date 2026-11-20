import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AccountStatement } from "@/components/store/AccountStatement";

import { customer } from "../fixtures";
import { callsTo, json, stubApi } from "../http";
import { renderWithQuery, router, searchParams } from "../render";

const statement = {
  data: [
    {
      date: "2026-10-03T08:00:00Z",
      type: "order",
      type_label: "طلب مسلَّم",
      order_number: 1001,
      return_number: null,
      debit: "450.00",
      credit: "0.00",
      balance: "450.00",
      method: null,
      method_label: null,
      reference: null,
    },
  ],
  links: {},
  meta: {
    current_page: 1,
    last_page: 1,
    total: 1,
    per_page: 24,
    from: 1,
    to: 1,
    links: [],
    path: "",
    opening_balance: "0.00",
    closing_balance: "450.00",
  },
  summary: {
    outstanding: "450.00",
    credit_limit: "1000.00",
    available_credit: "550.00",
    credit_balance: "0.00",
    overdue: "450.00",
  },
};

beforeEach(() => {
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("AccountStatement", () => {
  it("BR-C08 shows what the customer owes, what is overdue and their ledger linked to their orders", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/account/statement": json(200, statement),
    });

    renderWithQuery(<AccountStatement />);

    const summary = await screen.findByTestId("account-statement-summary");
    expect(summary).toHaveTextContent("المستحق عليك450.00 ج.م");
    expect(summary).toHaveTextContent("المتاح من الحد550.00 ج.م");
    expect(screen.getByRole("link", { name: "طلب #1001" })).toHaveAttribute("href", "/account/orders/1001");
    expect(screen.getByTestId("ledger-closing")).toHaveTextContent("450.00 ج.م");
  });

  it("BR-C10 says how a payment came in and its reference", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/account/statement": json(200, {
        ...statement,
        data: [
          ...statement.data,
          {
            date: "2026-10-04T08:00:00Z",
            type: "payment",
            type_label: "تحصيل",
            order_number: 1001,
            return_number: null,
            debit: "0.00",
            credit: "150.00",
            balance: "300.00",
            method: "instapay",
            method_label: "إنستاباي",
            reference: "IP-55120",
          },
        ],
      }),
    });

    renderWithQuery(<AccountStatement />);

    const methods = await screen.findAllByTestId("ledger-method");
    expect(methods).toHaveLength(1);
    expect(methods[0]).toHaveTextContent("إنستاباي · IP-55120");
  });

  it("D60 sends the range from the URL and writes a new one back", async () => {
    searchParams.value = new URLSearchParams("from=2026-10-01");
    const fetchMock = stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/account/statement": json(200, statement),
    });
    const user = userEvent.setup();
    renderWithQuery(<AccountStatement />);

    await screen.findByTestId("ledger");
    expect(callsTo(fetchMock, "GET /api/store/account/statement")[0]?.url.searchParams.get("from")).toBe("2026-10-01");
    const print = vi.spyOn(window, "print").mockImplementation(() => undefined);
    await user.click(screen.getByRole("button", { name: "طباعة" }));

    expect(print).toHaveBeenCalled();
  });

  it("says when there is nothing in the range", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/account/statement": json(200, {
        ...statement,
        data: [],
        meta: { ...statement.meta, closing_balance: "0.00" },
      }),
    });

    renderWithQuery(<AccountStatement />);

    expect(await screen.findByText("لا توجد حركات في هذه الفترة")).toBeInTheDocument();
  });
});
