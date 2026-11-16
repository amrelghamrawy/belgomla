import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdjustForm } from "@/components/dashboard/inventory/AdjustStockSheet";
import { BelowReservedError } from "@/components/dashboard/inventory/BelowReservedError";
import { BulkAdjustTable } from "@/components/dashboard/inventory/InventoryPage";
import { CostForm } from "@/components/dashboard/inventory/ReceiveStockSheet";
import { ApiError } from "@/lib/api/errors";
import type { DashboardSku, InventoryRow } from "@/lib/api/types";
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
    variant: null,
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

describe("variant rows (V2 Task 14)", () => {
  it("BR-P14 shows the variant label next to the product name", () => {
    stubApi({});
    renderWithQuery(
      <BulkAdjustTable rows={[row({ name: "قلم جاف", variant: "أزرق / M" }), row({ sku_id: 6 })]} onDone={vi.fn()} />,
    );

    expect(screen.getByText("أزرق / M")).toBeInTheDocument();
    expect(screen.getAllByRole("row")).toHaveLength(3);
  });
});

function sku(): DashboardSku {
  return {
    id: 5,
    code: "ADW-SCI-1",
    selling_unit: { id: 2, name: "كرتونة" },
    base_unit: { id: 1, name: "قطعة" },
    conversion_factor: 20,
    cover_price: null,
    base_price: "100.00",
    base_price_percent: null,
    moq: 5,
    units: [
      { id: 11, unit: { id: 1, name: "قطعة" }, conversion_factor: 1, cover_price: null, base_price: "6.00", base_price_percent: null, moq: 1, is_default: false },
      { id: 12, unit: { id: 2, name: "كرتونة" }, conversion_factor: 20, cover_price: null, base_price: "100.00", base_price_percent: null, moq: 5, is_default: true },
    ],
  } as DashboardSku;
}

describe("CostForm (BR-INV09, D96)", () => {
  it("BR-INV09 receives cartons at the price per carton, sending the unit, the quantity, the price and the note as typed", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/inventory/5/receive": json(200, { data: row({ on_hand: 140, avg_cost: "5.5000" }) }),
    });
    const onDone = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<CostForm row={row({ avg_cost: "5.0000" })} sku={sku()} mode="receive" onDone={onDone} />);

    expect(screen.getByTestId("current-cost")).toHaveTextContent("متوسط التكلفة: 5.00 ج.م للوحدة الأساسية");
    await user.selectOptions(screen.getByLabelText("الوحدة"), "كرتونة (20)");
    await user.type(screen.getByLabelText("الكمية المستلمة"), "2");
    await user.type(screen.getByLabelText("سعر الشراء للوحدة"), "120");
    await user.type(screen.getByLabelText("ملاحظة (اختياري)"), "مكتبة النور");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onDone).toHaveBeenCalled());
    const call = callsTo(fetchMock, "POST /api/dashboard/inventory/5/receive")[0];
    expect(call?.body).toEqual({ quantity: 2, unit_cost: "120", sku_unit_id: 12, note: "مكتبة النور" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^receive-5-[0-9a-f]{32}$/);
  });

  it("lists the base unit first and the larger units, never the base unit twice", () => {
    stubApi({});
    renderWithQuery(<CostForm row={row()} sku={sku()} mode="receive" onDone={vi.fn()} />);

    expect(within(screen.getByLabelText("الوحدة")).getAllByRole("option").map((option) => option.textContent)).toEqual([
      "قطعة (الوحدة الأساسية)",
      "كرتونة (20)",
    ]);
  });

  it("checks the quantity and the price before sending", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(<CostForm row={row()} sku={sku()} mode="receive" onDone={vi.fn()} />);

    await user.type(screen.getByLabelText("الكمية المستلمة"), "0");
    await user.type(screen.getByLabelText("سعر الشراء للوحدة"), "1.005");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(screen.getByText("اكتب سعرًا صحيحًا (رقم بحد أقصى خانتين عشريتين)")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/inventory/5/receive")).toHaveLength(0);
  });

  it("D96 d sets the cost per base unit without a quantity and says when none is known yet", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/inventory/5/cost": json(200, { data: row({ avg_cost: "4.7500" }) }) });
    const onDone = vi.fn();
    const user = userEvent.setup();
    renderWithQuery(<CostForm row={row({ avg_cost: null })} sku={sku()} mode="cost" onDone={onDone} />);

    expect(screen.getByTestId("current-cost")).toHaveTextContent("متوسط التكلفة: غير محددة");
    expect(screen.queryByLabelText("الكمية المستلمة")).toBeNull();
    await user.type(screen.getByLabelText("سعر الشراء للوحدة"), "4.75");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(callsTo(fetchMock, "PUT /api/dashboard/inventory/5/cost")[0]?.body).toEqual({ unit_cost: "4.75", sku_unit_id: null });
  });

  it("D93 shows the refusal of a bundle", async () => {
    stubApi({
      "PUT /api/dashboard/inventory/5/cost": envelopeError(409, "is_bundle", "هذا المنتج باقة وليس له مخزون: استلم مكوناته"),
    });
    const user = userEvent.setup();
    renderWithQuery(<CostForm row={row()} sku={sku()} mode="cost" onDone={vi.fn()} />);

    await user.type(screen.getByLabelText("سعر الشراء للوحدة"), "1");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("هذا المنتج باقة وليس له مخزون: استلم مكوناته");
  });
});
