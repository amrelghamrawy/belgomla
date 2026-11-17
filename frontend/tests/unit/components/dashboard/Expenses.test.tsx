import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ExpenseForm, ExpensesPage } from "@/components/dashboard/expenses/ExpensesPage";
import type { Expense, ExpenseCategoryOption } from "@/lib/api/types";

import { signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

const CATEGORIES: ExpenseCategoryOption[] = [
  { code: "rent", label: "إيجار", needs_note: false },
  { code: "transport", label: "نقل وشحن", needs_note: false },
  { code: "other", label: "أخرى", needs_note: true },
];

function expense(overrides: Partial<Expense> = {}): Expense {
  return {
    id: 11,
    spent_on: "2026-10-05",
    amount: "350.50",
    category: "transport",
    category_label: "نقل وشحن",
    note: null,
    created_by: 1,
    created_at: "2026-10-05T08:00:00Z",
    voided_at: null,
    void_reason: null,
    ...overrides,
  };
}

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true, now: new Date("2026-10-10T09:00:00Z") });
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

describe("ExpensesPage (D100)", () => {
  it("lists the expenses with the live total and marks a voided one with its reason", async () => {
    stubApi({
      "GET /api/dashboard/expenses": json(200, {
        ...page([expense(), expense({ id: 12, amount: "120.25", voided_at: "2026-10-06T08:00:00Z", void_reason: "مكرر" })]),
        meta: { ...page([]).meta, total: 2, total_amount: "350.50" },
      }),
      "GET /api/dashboard/expenses/categories": json(200, { data: CATEGORIES }),
    });

    renderWithQuery(<ExpensesPage />, signedInAs("owner"));

    expect(await screen.findByTestId("expenses-total")).toHaveTextContent("الإجمالي: 350.50 ج.م");
    const voided = screen.getByTestId("expense-12");
    expect(within(voided).getByText("ملغى")).toBeInTheDocument();
    expect(within(voided).getByText("مكرر")).toBeInTheDocument();
    expect(within(screen.getByTestId("expense-11")).getByRole("button", { name: "إلغاء" })).toBeInTheDocument();
  });
});

describe("ExpenseForm (D100 a)", () => {
  it("records with today in Cairo by default and sends what was typed", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/expenses": json(201, { data: expense({ id: 13, category: "rent" }) }) });
    const onDone = vi.fn();
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithQuery(<ExpenseForm categories={CATEGORIES} onDone={onDone} />);

    expect(screen.getByLabelText("التاريخ")).toHaveValue("2026-10-10");
    await user.selectOptions(screen.getByLabelText("البند"), "إيجار");
    await user.type(screen.getByLabelText("المبلغ"), "1500");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onDone).toHaveBeenCalled());
    const call = callsTo(fetchMock, "POST /api/dashboard/expenses")[0];
    expect(call?.body).toEqual({ spent_on: "2026-10-10", amount: "1500", category: "rent", note: null });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^expense-[0-9a-f]{32}$/);
  });

  it("says goods are not expenses, under the category", () => {
    stubApi({});
    renderWithQuery(<ExpenseForm categories={CATEGORIES} onDone={vi.fn()} />);

    expect(screen.getByText("ثمن البضاعة يُسجَّل من «استلام بضاعة» في المخزون، لا هنا.")).toBeInTheDocument();
  });

  it("checks the amount, the category and the note of «أخرى» before sending", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithQuery(<ExpenseForm categories={CATEGORIES} onDone={vi.fn()} />);

    await user.type(screen.getByLabelText("المبلغ"), "0");
    await user.click(screen.getByRole("button", { name: "حفظ" }));
    expect(screen.getByText("اكتب مبلغًا صحيحًا أكبر من صفر (بحد أقصى خانتين عشريتين)")).toBeInTheDocument();
    expect(screen.getAllByText("اختر البند").length).toBeGreaterThan(1);

    await user.selectOptions(screen.getByLabelText("البند"), "أخرى");
    await user.clear(screen.getByLabelText("المبلغ"));
    await user.type(screen.getByLabelText("المبلغ"), "20");
    await user.click(screen.getByRole("button", { name: "حفظ" }));
    expect(screen.getByText("اكتب ما هو هذا المصروف")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/expenses")).toHaveLength(0);
  });

  it("refuses a day after today", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithQuery(<ExpenseForm categories={CATEGORIES} onDone={vi.fn()} />);

    await user.clear(screen.getByLabelText("التاريخ"));
    await user.type(screen.getByLabelText("التاريخ"), "2026-10-11");
    await user.selectOptions(screen.getByLabelText("البند"), "إيجار");
    await user.type(screen.getByLabelText("المبلغ"), "5");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(screen.getByText("اختر تاريخًا لا يتجاوز اليوم")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/expenses")).toHaveLength(0);
  });

  it("shows a refusal from the server", async () => {
    stubApi({
      "POST /api/dashboard/expenses": envelopeError(422, "validation_failed", "البيانات غير صحيحة", { amount: ["x"] }),
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithQuery(<ExpenseForm categories={CATEGORIES} onDone={vi.fn()} />);

    await user.selectOptions(screen.getByLabelText("البند"), "نقل وشحن");
    await user.type(screen.getByLabelText("المبلغ"), "5");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });
});
