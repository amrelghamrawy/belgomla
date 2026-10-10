import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdjustForm } from "@/components/dashboard/inventory/AdjustStockSheet";
import { BelowReservedError } from "@/components/dashboard/inventory/BelowReservedError";
import { BulkAdjustTable } from "@/components/dashboard/inventory/InventoryPage";
import { ApiError } from "@/lib/api/errors";
import type { InventoryRow } from "@/lib/api/types";
import { belowReservedDetails } from "@/lib/dashboard/inventory";

import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

const MESSAGE = "لا يمكن تقليل المخزون إلى 3 لأن 5 وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا.";

function belowReserved() {
  return new ApiError(
    422,
    "inventory_below_reserved",
    MESSAGE,
    {
      reserved: 5,
      min_allowed: 5,
      orders: [
        { number: 1024, status: "confirmed", quantity_base: 3 },
        { number: 1031, status: "pending", quantity_base: 2 },
      ],
    },
    null,
  );
}

function row(overrides: Partial<InventoryRow> = {}): InventoryRow {
  return {
    sku_id: 5,
    product_id: 3,
    code: "ADW-SCI-1",
    name: "الأضواء علوم",
    status: "active",
    on_hand: 100,
    reserved: 80,
    available: 20,
    min_allowed: 80,
    moq: 5,
    conversion_factor: 20,
    low_stock: true,
    ...overrides,
  };
}

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

describe("BelowReservedError", () => {
  it("BR-INV08 shows the backend message and links every holding order to its page", () => {
    render(<BelowReservedError error={belowReserved()} />);

    const alert = screen.getByTestId("below-reserved");
    expect(alert).toHaveTextContent(MESSAGE);
    expect(within(alert).getByRole("link", { name: "#1024" })).toHaveAttribute("href", "/dashboard/orders/1024");
    expect(within(alert).getByRole("link", { name: "#1031" })).toHaveAttribute("href", "/dashboard/orders/1031");
  });

  it("renders nothing for other errors", () => {
    const { container } = render(
      <BelowReservedError error={new ApiError(422, "validation_failed", "بعض البيانات غير صحيحة", null, null)} />,
    );

    expect(container).toBeEmptyDOMElement();
    expect(belowReservedDetails(new Error("x"))).toBeNull();
  });
});

describe("AdjustForm", () => {
  it("BR-INV08 shows on hand, reserved and available and the exact minimum line", () => {
    renderWithQuery(<AdjustForm row={row()} onDone={vi.fn()} />);

    expect(screen.getByTestId("min-allowed")).toHaveTextContent("أقل كمية ممكنة: 80 (محجوزة لطلبات قائمة)");
    expect(screen.getByText("الرصيد الفعلي").nextSibling).toHaveTextContent("100");
    expect(screen.getByText("محجوز").nextSibling).toHaveTextContent("80");
    expect(screen.getByText("متاح").nextSibling).toHaveTextContent("20");
  });

  it("requires a reason and sends the new on hand", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/inventory/5/adjust": json(200, { data: row({ on_hand: 150 }) }) });
    const onDone = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<AdjustForm row={row()} onDone={onDone} />);

    await user.clear(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية)"));
    await user.type(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية)"), "150");
    await user.click(screen.getByRole("button", { name: "حفظ" }));
    expect(screen.getByText("اكتب السبب")).toBeInTheDocument();

    await user.type(screen.getByLabelText("سبب التعديل"), "جرد");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(callsTo(fetchMock, "POST /api/dashboard/inventory/5/adjust")[0]?.body).toEqual({
      new_on_hand: 150,
      reason: "جرد",
    });
  });

  it("BR-INV08 renders the refusal with the holding orders as links", async () => {
    stubApi({
      "POST /api/dashboard/inventory/5/adjust": envelopeError(422, "inventory_below_reserved", MESSAGE, {
        reserved: 5,
        min_allowed: 5,
        orders: [{ number: 1024, status: "confirmed", quantity_base: 3 }],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<AdjustForm row={row()} onDone={vi.fn()} />);

    await user.clear(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية)"));
    await user.type(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية)"), "3");
    await user.type(screen.getByLabelText("سبب التعديل"), "جرد");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    const alert = await screen.findByTestId("below-reserved");
    expect(alert).toHaveTextContent(MESSAGE);
    expect(within(alert).getByRole("link", { name: "#1024" })).toHaveAttribute("href", "/dashboard/orders/1024");
  });
});

describe("BulkAdjustTable", () => {
  it("sends only the filled rows with the shared reason and lists the skipped ones with their reason", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/inventory/bulk-adjust": json(200, {
        data: {
          applied: 1,
          skipped: [{ sku_id: 6, reason: "below_reserved", reserved: 40, min_allowed: 40 }],
          batch_id: "b",
        },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <BulkAdjustTable
        rows={[row(), row({ sku_id: 6, code: "ADW-AR-1" }), row({ sku_id: 7, code: "ADW-EN-1" })]}
        onDone={vi.fn()}
      />,
    );

    await user.type(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية) ADW-SCI-1"), "120");
    await user.type(screen.getByLabelText("الرصيد الجديد (بالوحدة الأساسية) ADW-AR-1"), "10");
    await user.type(screen.getByLabelText("سبب التعديل (لكل الأصناف)"), "جرد سنوي");
    await user.click(screen.getByRole("button", { name: "تطبيق التعديلات" }));

    const skipped = await screen.findByTestId("bulk-skipped");
    expect(skipped).toHaveTextContent("ADW-AR-1: أقل من المحجوز (أقل كمية ممكنة 40)");
    expect(callsTo(fetchMock, "POST /api/dashboard/inventory/bulk-adjust")[0]?.body).toEqual([
      { sku_id: 5, new_on_hand: 120, reason: "جرد سنوي" },
      { sku_id: 6, new_on_hand: 10, reason: "جرد سنوي" },
    ]);
  });

  it("says so when no row was filled", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<BulkAdjustTable rows={[row()]} onDone={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "تطبيق التعديلات" }));

    expect(screen.getByRole("alert")).toHaveTextContent("لم تُدخل أي رصيد جديد");
    expect(callsTo(fetchMock, "POST /api/dashboard/inventory/bulk-adjust")).toHaveLength(0);
  });
});
