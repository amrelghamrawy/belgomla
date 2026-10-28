import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CART_DEBOUNCE_MS, CartView } from "@/components/store/CartView";
import { MovProgress } from "@/components/store/MovProgress";

import { cart, cartItem, customer } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { renderWithQuery, router } from "../render";

beforeEach(() => {
  router.replace.mockReset();
});

describe("MovProgress", () => {
  it("BR-MOV02 shows what is left to the minimum order value", () => {
    render(
      <MovProgress cart={{ items_total: "700.00", mov: { enabled: true, amount: "1000.00", satisfied: false } }} />,
    );

    expect(screen.getByText("باقي 300.00 ج.م للحد الأدنى للطلب")).toBeInTheDocument();
    expect(screen.getByRole("progressbar", { name: "التقدم نحو الحد الأدنى للطلب" })).toBeInTheDocument();
  });

  it("BR-MOV02 says when the minimum is reached", () => {
    render(
      <MovProgress cart={{ items_total: "1050.00", mov: { enabled: true, amount: "1000.00", satisfied: true } }} />,
    );

    expect(screen.getByText("وصلت للحد الأدنى للطلب")).toBeInTheDocument();
  });

  it("shows nothing when the store has no minimum", () => {
    const { container } = render(
      <MovProgress cart={{ items_total: "10.00", mov: { enabled: false, amount: null, satisfied: true } }} />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});

describe("CartView", () => {
  function stubCart(answer = cart(), routes: Parameters<typeof stubApi>[0] = {}) {
    return stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/cart": json(200, { data: answer }),
      ...routes,
    });
  }

  it("D91 shows the same product in two units as two lines, each with its unit", async () => {
    stubCart(
      cart({
        items: [
          cartItem({
            sku_unit_id: 601,
            unit: "قطعة",
            conversion_factor: 1,
            quantity: 5,
            unit_price: "10.00",
            line_total: "50.00",
          }),
          cartItem({
            sku_unit_id: 602,
            unit: "كرتونة",
            conversion_factor: 12,
            quantity: 2,
            unit_price: "110.00",
            line_total: "220.00",
          }),
        ],
        items_total: "270.00",
      }),
    );
    renderWithQuery(<CartView />);

    expect(await screen.findByTestId("cart-unit-601")).toHaveTextContent("الوحدة: قطعة");
    expect(screen.getByTestId("cart-unit-602")).toHaveTextContent("الوحدة: كرتونة (12)");
    expect(screen.getAllByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toHaveLength(2);
  });

  it("BR-PR04 shows each line priced by the server and the total", async () => {
    stubCart(
      cart({
        items: [cartItem({ quantity: 12, unit_price: "85.00", tier_min_quantity: 10, line_total: "1020.00" })],
        items_total: "1020.00",
      }),
    );
    renderWithQuery(<CartView />);

    expect(await screen.findByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toBeInTheDocument();
    expect(screen.getByText(/85\.00 ج\.م للوحدة/)).toHaveTextContent("سعر الكمية من 10");
    expect(screen.getByTestId("cart-total")).toHaveTextContent("1,020.00 ج.م");
    expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(12);
  });

  it("BR-P04 lists the products removed from the cart because they are no longer active", async () => {
    stubCart(cart({ removed_items: [{ product_name: "كتاب قديم", reason: "inactive" }] }));
    renderWithQuery(<CartView />);

    expect(await screen.findByText("تم حذف بعض الأصناف من سلتك لأنها لم تعد متاحة:")).toBeInTheDocument();
    expect(screen.getByText("كتاب قديم")).toBeInTheDocument();
  });

  it("BR-MOV02 shows the minimum order bar", async () => {
    stubCart(cart({ items_total: "200.00", mov: { enabled: true, amount: "500.00", satisfied: false } }));
    renderWithQuery(<CartView />);

    expect(await screen.findByText("باقي 300.00 ج.م للحد الأدنى للطلب")).toBeInTheDocument();
  });

  it("shows the empty cart with a way back to the products", async () => {
    stubCart(cart({ items: [], items_total: "0.00" }));
    renderWithQuery(<CartView />);

    expect(await screen.findByText("سلتك فارغة")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "تصفح المنتجات" })).toHaveAttribute("href", "/");
  });

  it("removes a line", async () => {
    const fetchMock = stubCart(cart(), {
      "DELETE /api/store/cart/items/601": json(200, { data: cart({ items: [] }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CartView />);

    await user.click(await screen.findByRole("button", { name: "حذف من السلة: الأضواء علوم أولى إعدادي" }));

    expect(await screen.findByText("سلتك فارغة")).toBeInTheDocument();
    expect(callsTo(fetchMock, "DELETE /api/store/cart/items/601")).toHaveLength(1);
  });

  it("D51 a customer who is not active sees why there is no cart", async () => {
    const fetchMock = stubApi({ "GET /api/store/me": json(200, { data: customer({ status: "blocked" }) }) });
    renderWithQuery(<CartView />);

    expect(await screen.findByText("تم إيقاف حسابك، تواصل مع المتجر")).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(0);
  });

  it("sends a guest to log in", async () => {
    stubApi({ "GET /api/store/me": envelopeError(401, "unauthenticated", "x") });
    renderWithQuery(<CartView />);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith(expect.stringMatching(/^\/account\/login\?next=/)));
  });

  describe("changing a quantity", () => {
    beforeEach(() => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("waits for the taps to stop, then sends one update", async () => {
      const fetchMock = stubCart(cart(), {
        "PATCH /api/store/cart/items/601": json(200, {
          data: cart({ items: [cartItem({ quantity: 8, line_total: "720.00" })] }),
        }),
      });
      renderWithQuery(<CartView />);
      const increase = await screen.findByRole("button", { name: "زيادة الكمية" });

      fireEvent.click(increase);
      fireEvent.click(increase);
      expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(8);
      expect(callsTo(fetchMock, "PATCH /api/store/cart/items/601")).toHaveLength(0);
      act(() => vi.advanceTimersByTime(CART_DEBOUNCE_MS));

      await waitFor(() => expect(screen.getByText("720.00 ج.م")).toBeInTheDocument());
      expect(callsTo(fetchMock, "PATCH /api/store/cart/items/601").map((call) => call.body)).toEqual([{ quantity: 8 }]);
    });

    it("goes back to the server's quantity when the update is refused", async () => {
      stubCart(cart(), {
        "PATCH /api/store/cart/items/601": envelopeError(422, "cart_line_invalid", "الكمية غير متوفرة"),
      });
      renderWithQuery(<CartView />);
      fireEvent.click(await screen.findByRole("button", { name: "زيادة الكمية" }));

      act(() => vi.advanceTimersByTime(CART_DEBOUNCE_MS));

      await waitFor(() => expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(6));
    });
  });
});

describe("CartView pre-orders (BR-O14)", () => {
  const preorderLine = {
    ...cartItem({
      sku_id: 777,
      sku_unit_id: 777,
      product_name: "سلاح التلميذ ٢٠٢٧",
      product_slug: "selah",
      quantity: 5,
      available: 0,
      line_total: "450.00",
    }),
    expected_at: "2026-12-01",
  };

  function stubPreorders(
    answer = cart({ preorders: { items: [preorderLine], items_total: "450.00" } }),
    routes: Parameters<typeof stubApi>[0] = {},
  ) {
    return stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/cart": json(200, { data: answer }),
      ...routes,
    });
  }

  it("lists the pre-order lines apart with the expected day, their indicative total and their own confirmation", async () => {
    stubPreorders();
    renderWithQuery(<CartView />);

    const section = await screen.findByTestId("preorders");
    expect(section).toHaveTextContent("الحجز المسبق");
    expect(section).toHaveTextContent("سلاح التلميذ ٢٠٢٧");
    expect(section).toHaveTextContent("متوقع 1 ديسمبر 2026");
    expect(screen.getByTestId("preorders-total")).toHaveTextContent("450.00 ج.م");
    expect(screen.getByRole("link", { name: "تأكيد الحجز" })).toHaveAttribute("href", "/checkout/preorder");
    expect(screen.getByRole("link", { name: "إتمام الطلب" })).toHaveAttribute("href", "/checkout");
  });

  it("a cart with only pre-orders is not empty and offers no checkout", async () => {
    stubPreorders(
      cart({ items: [], items_total: "0.00", preorders: { items: [preorderLine], items_total: "450.00" } }),
    );
    renderWithQuery(<CartView />);

    expect(await screen.findByTestId("preorders")).toBeInTheDocument();
    expect(screen.queryByText("سلتك فارغة")).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "إتمام الطلب" })).not.toBeInTheDocument();
  });

  it("removes a pre-order line by its kind", async () => {
    const fetchMock = stubPreorders(undefined, {
      "DELETE /api/store/cart/items/777": json(200, { data: cart() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CartView />);

    await user.click(await screen.findByRole("button", { name: "حذف من السلة: سلاح التلميذ ٢٠٢٧" }));

    await waitFor(() => expect(screen.queryByTestId("preorders")).not.toBeInTheDocument());
    expect(callsTo(fetchMock, "DELETE /api/store/cart/items/777")[0]?.url.searchParams.get("kind")).toBe("preorder");
  });
});

describe("CartView customer prices (BR-PR14)", () => {
  it("marks a line priced at the customer's negotiated price, not a tier line", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/cart": json(200, {
        data: cart({
          items: [
            cartItem({
              sku_id: 1,
              sku_unit_id: 1,
              product_name: "سعر خاص",
              unit_price: "70.00",
              price_source: "customer",
            }),
            cartItem({
              sku_id: 2,
              sku_unit_id: 2,
              product_name: "سعر كمية",
              unit_price: "85.00",
              tier_min_quantity: 10,
              price_source: "tier",
            }),
          ],
        }),
      }),
    });
    renderWithQuery(<CartView />);

    await screen.findByRole("link", { name: "سعر خاص" });
    expect(screen.getAllByTestId("your-price")).toHaveLength(1);
  });
});

describe("CartView offers (BR-PR15)", () => {
  it("shows the offer badge on a line a promotion priced", async () => {
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/cart": json(200, {
        data: cart({
          items: [
            cartItem({
              sku_id: 1,
              sku_unit_id: 1,
              product_name: "كتاب العرض",
              unit_price: "76.50",
              price_source: "promotion",
              promotion: { id: 4, name: "عرض", discount_percent: "15.00", ends_at: "2030-01-01T00:00:00Z" },
            }),
            cartItem({ sku_id: 2, sku_unit_id: 2, product_name: "كتاب عادي" }),
          ],
        }),
      }),
    });
    renderWithQuery(<CartView />);

    await screen.findByRole("link", { name: "كتاب العرض" });
    expect(screen.getAllByTestId("offer-badge")).toHaveLength(1);
    expect(screen.getByTestId("offer-badge")).toHaveTextContent("عرض 15%");
  });
});
