import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PreorderPanel } from "@/components/dashboard/catalog/PreorderPanel";
import { ProductForm } from "@/components/dashboard/catalog/ProductEditor";
import type { PreorderDemand } from "@/lib/api/types";

import { category, dashboardProduct, selectField, signedInAs, unit } from "../../dashboard-fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery } from "../../render";

// BR-O14, D88 on the dashboard: the product form's pre-order settings and the product page's demand and conversion.

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
});

function demand(overrides: Partial<PreorderDemand> = {}): PreorderDemand {
  return {
    product_id: 3,
    status: "coming_soon",
    preorder_enabled: true,
    expected_at: "2026-12-01",
    limit: 100,
    remaining: 80,
    quantity: 20,
    customers: 2,
    preorders: 3,
    ...overrides,
  };
}

const preorderable = (status: "coming_soon" | "active" = "coming_soon") =>
  dashboardProduct({ status, preorder: { enabled: true, expected_at: "2026-12-01", limit: 100 } });

describe("ProductForm pre-order settings", () => {
  it("sends the pre-order switch, the expected day and the cap with the product", async () => {
    const fetchMock = stubApi({
      "PUT /api/dashboard/products/3": json(200, { data: preorderable() }),
      "GET /api/dashboard/products/3/preorder-demand": json(200, { data: demand() }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <ProductForm
        product={dashboardProduct()}
        definitions={[selectField()]}
        units={[unit()]}
        categories={[category()]}
      />,
    );

    const settings = within(screen.getByTestId("preorder-settings"));
    await user.click(settings.getByRole("switch", { name: "السماح بالحجز المسبق" }));
    await user.type(settings.getByLabelText(/تاريخ التوفر المتوقع/), "2026-12-01");
    await user.type(settings.getByLabelText(/أقصى كمية للحجز/), "300");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")[0]?.body).toMatchObject({
      preorder: { enabled: true, expected_at: "2026-12-01", limit: 300 },
    });
  });

  it("refuses a cap below one before calling the API", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderWithQuery(
      <ProductForm
        product={dashboardProduct()}
        definitions={[selectField()]}
        units={[unit()]}
        categories={[category()]}
      />,
    );

    await user.type(screen.getByLabelText(/أقصى كمية للحجز/), "0");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("رقم صحيح أكبر من صفر")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(0);
  });
});

describe("PreorderPanel", () => {
  it("shows nothing for a product that never took pre-orders", () => {
    stubApi({});
    const { container } = renderWithQuery(<PreorderPanel product={dashboardProduct()} />, signedInAs("owner"));

    expect(container).toBeEmptyDOMElement();
  });

  it("shows the demand while coming soon and asks to activate and stock the product before converting", async () => {
    stubApi({ "GET /api/dashboard/products/3/preorder-demand": json(200, { data: demand() }) });
    renderWithQuery(<PreorderPanel product={preorderable()} />, signedInAs("owner"));

    expect(await screen.findByTestId("preorder-demand")).toHaveTextContent("20 كرتونة محجوزة");
    expect(screen.getByTestId("preorder-demand")).toHaveTextContent("2 عميل");
    expect(screen.getByTestId("preorder-demand")).toHaveTextContent("متبقٍ للحجز 80 كرتونة");
    expect(screen.getByText("فعّل المنتج وأضف المخزون قبل تحويل الحجوزات.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "تحويل الحجوزات إلى طلبات" })).not.toBeInTheDocument();
  });

  it("converts once active and shows the summary with links to the new orders", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/products/3/preorder-demand": [
        json(200, { data: demand({ status: "active" }) }),
        json(200, { data: demand({ status: "active", quantity: 5, preorders: 1, customers: 1 }) }),
      ],
      "POST /api/dashboard/products/3/convert-preorders": json(200, {
        data: {
          converted_orders: [
            { number: 12, preorder_number: 7, quantity: 10, order_total: "850.00" },
            { number: 13, preorder_number: 8, quantity: 5, order_total: "450.00" },
          ],
          partially: 1,
          untouched: 1,
        },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<PreorderPanel product={preorderable("active")} />, signedInAs("owner"));

    await user.click(await screen.findByRole("button", { name: "تحويل الحجوزات إلى طلبات" }));

    const summary = await screen.findByTestId("conversion-summary");
    expect(within(summary).getByRole("link", { name: "طلب #12 من الحجز #7 (10)" })).toHaveAttribute(
      "href",
      "/dashboard/orders/12",
    );
    expect(summary).toHaveTextContent("1 حجز تحوّل جزئيًا وبقي الباقي محجوزًا");
    expect(summary).toHaveTextContent("1 حجز لم يتحول لعدم كفاية المخزون");
    expect(
      callsTo(fetchMock, "POST /api/dashboard/products/3/convert-preorders")[0]?.headers["Idempotency-Key"],
    ).toMatch(/^convert-preorders-3-/);
  });

  it("says when no stock let any pre-order through", async () => {
    stubApi({
      "GET /api/dashboard/products/3/preorder-demand": json(200, { data: demand({ status: "active" }) }),
      "POST /api/dashboard/products/3/convert-preorders": json(200, {
        data: { converted_orders: [], partially: 0, untouched: 3 },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<PreorderPanel product={preorderable("active")} />, signedInAs("owner"));

    await user.click(await screen.findByRole("button", { name: "تحويل الحجوزات إلى طلبات" }));

    expect(await screen.findByTestId("conversion-summary")).toHaveTextContent("لم يتحول أي حجز: لا يوجد مخزون كافٍ");
  });

  it("shows the server's refusal", async () => {
    stubApi({
      "GET /api/dashboard/products/3/preorder-demand": json(200, { data: demand({ status: "active" }) }),
      "POST /api/dashboard/products/3/convert-preorders": envelopeError(409, "product_not_active", "فعّل المنتج أولًا"),
    });
    const user = userEvent.setup();
    renderWithQuery(<PreorderPanel product={preorderable("active")} />, signedInAs("owner"));

    await user.click(await screen.findByRole("button", { name: "تحويل الحجوزات إلى طلبات" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("فعّل المنتج أولًا"));
  });

  it("D82 a user without orders.manage reads the demand but cannot convert", async () => {
    stubApi({ "GET /api/dashboard/products/3/preorder-demand": json(200, { data: demand({ status: "active" }) }) });
    renderWithQuery(<PreorderPanel product={preorderable("active")} />, signedInAs("accountant"));

    expect(await screen.findByTestId("preorder-demand")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "تحويل الحجوزات إلى طلبات" })).not.toBeInTheDocument();
  });
});
