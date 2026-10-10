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
      "DELETE /api/store/cart/items/501": json(200, { data: cart({ items: [] }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CartView />);

    await user.click(await screen.findByRole("button", { name: "حذف من السلة: الأضواء علوم أولى إعدادي" }));

    expect(await screen.findByText("سلتك فارغة")).toBeInTheDocument();
    expect(callsTo(fetchMock, "DELETE /api/store/cart/items/501")).toHaveLength(1);
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
        "PATCH /api/store/cart/items/501": json(200, {
          data: cart({ items: [cartItem({ quantity: 8, line_total: "720.00" })] }),
        }),
      });
      renderWithQuery(<CartView />);
      const increase = await screen.findByRole("button", { name: "زيادة الكمية" });

      fireEvent.click(increase);
      fireEvent.click(increase);
      expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(8);
      expect(callsTo(fetchMock, "PATCH /api/store/cart/items/501")).toHaveLength(0);
      act(() => vi.advanceTimersByTime(CART_DEBOUNCE_MS));

      await waitFor(() => expect(screen.getByText("720.00 ج.م")).toBeInTheDocument());
      expect(callsTo(fetchMock, "PATCH /api/store/cart/items/501").map((call) => call.body)).toEqual([{ quantity: 8 }]);
    });

    it("goes back to the server's quantity when the update is refused", async () => {
      stubCart(cart(), {
        "PATCH /api/store/cart/items/501": envelopeError(422, "cart_line_invalid", "الكمية غير متوفرة"),
      });
      renderWithQuery(<CartView />);
      fireEvent.click(await screen.findByRole("button", { name: "زيادة الكمية" }));

      act(() => vi.advanceTimersByTime(CART_DEBOUNCE_MS));

      await waitFor(() => expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(6));
    });
  });
});
