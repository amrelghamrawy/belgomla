import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { creditTerms } from "@/components/dashboard/customers/CustomerDetailPage";
import { ApplyCreditDialog, creditToApply } from "@/components/dashboard/orders/ApplyCreditDialog";
import { ledgerReference } from "@/components/shared/LedgerTable";
import type { CustomerCredit, DashboardOrderDetail, LedgerRow } from "@/lib/api/types";

import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

function credit(overrides: Partial<CustomerCredit> = {}): CustomerCredit {
  return {
    id: 9,
    customer_id: 7,
    source_return_id: 3,
    amount: "200.00",
    remaining: "150.00",
    note: null,
    created_by: 1,
    created_at: "2026-10-04T08:00:00Z",
    is_voided: false,
    voided_at: null,
    void_reason: null,
    voided_by: null,
    ...overrides,
  };
}

const order = {
  number: 1002,
  status: "delivered",
  net_total: "540.00",
  collected_amount: "440.00",
  customer: { id: 7, name: "مكتبة الأمل", phone: "+201012345678" },
} as DashboardOrderDetail;

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

describe("credit helpers", () => {
  it("BR-C07 starts the amount at the smaller of the credit and the order's balance", () => {
    expect(creditToApply({ remaining: "150.00" }, "100.00")).toBe("100.00");
    expect(creditToApply({ remaining: "60.00" }, "100.00")).toBe("60.00");
  });

  it("BR-C05 BR-C06 turns the typed limit and terms into the request, blank as none, and refuses bad input", () => {
    expect(creditTerms(" 2000 ", "30")).toEqual({ credit_limit: "2000", payment_terms_days: 30 });
    expect(creditTerms("", "")).toEqual({ credit_limit: null, payment_terms_days: null });
    expect(creditTerms("abc", "")).toBeNull();
    expect(creditTerms("", "400")).toBeNull();
    expect(creditTerms("", "-1")).toBeNull();
  });

  it("BR-C08 names the order, or the return of an order, a ledger row refers to", () => {
    const row = { order_number: 1001, return_number: null } as LedgerRow;

    expect(ledgerReference(row)).toBe("طلب #1001");
    expect(ledgerReference({ ...row, return_number: 2 })).toBe("مرتجع #2 من طلب #1001");
    expect(ledgerReference({ ...row, order_number: null })).toBeNull();
  });
});

describe("ApplyCreditDialog", () => {
  it("shows nothing when the customer has no usable credit", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customers/7/credits": json(200, {
        data: [credit({ remaining: "0.00" }), credit({ id: 10, is_voided: true })],
      }),
    });
    renderWithQuery(<ApplyCreditDialog order={order} />);

    await waitFor(() => expect(callsTo(fetchMock, "GET /api/dashboard/customers/7/credits")).toHaveLength(1));
    expect(screen.queryByRole("button", { name: "خصم من رصيد العميل" })).not.toBeInTheDocument();
  });

  it("BR-C07 applies credit up to what the order owes, with an Idempotency-Key", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/customers/7/credits": json(200, { data: [credit()] }),
      "POST /api/dashboard/orders/1002/apply-credit": json(201, { data: {} }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ApplyCreditDialog order={order} />);

    await user.click(await screen.findByRole("button", { name: "خصم من رصيد العميل" }));
    expect(screen.getByLabelText("المبلغ")).toHaveValue("100.00");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "خصم من رصيد العميل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم الخصم من رصيد العميل"));
    const call = callsTo(fetchMock, "POST /api/dashboard/orders/1002/apply-credit")[0];
    expect(call?.body).toEqual({ credit_id: 9, amount: "100.00" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^credit-1002-/);
  });

  it("shows the server's refusal", async () => {
    stubApi({
      "GET /api/dashboard/customers/7/credits": json(200, { data: [credit()] }),
      "POST /api/dashboard/orders/1002/apply-credit": envelopeError(
        422,
        "exceeds_credit",
        "المبلغ أكبر من المتبقي في رصيد العميل (150.00)",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<ApplyCreditDialog order={order} />);

    await user.click(await screen.findByRole("button", { name: "خصم من رصيد العميل" }));
    await user.clear(screen.getByLabelText("المبلغ"));
    await user.type(screen.getByLabelText("المبلغ"), "200");
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "خصم من رصيد العميل" }));

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent(
      "المبلغ أكبر من المتبقي في رصيد العميل",
    );
  });
});
