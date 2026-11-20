import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { CartLine } from "@/components/store/CartView";
import { OrderDetailView } from "@/components/store/OrderDetailView";
import { ProductCard } from "@/components/store/ProductCard";
import { ProductDetail } from "@/components/store/ProductDetail";

import { bundleProduct, cart, cartItem, customer, order, orderItem } from "../fixtures";
import { json, stubApi } from "../http";
import { params, renderWithQuery } from "../render";

// V2 Task 15 (BR-P15, D93): bundles on the storefront and in the shopper's order.

const signedIn = {
  "GET /api/store/me": json(200, { data: customer() }),
  "GET /api/store/cart": json(200, { data: cart({ items: [] }) }),
};

describe("bundle card", () => {
  it("marks a bundle and says what it saves", () => {
    stubApi(signedIn);
    renderWithQuery(<ProductCard product={bundleProduct({ availability: 3 })} />);

    const badge = screen.getByTestId("bundle-card");
    expect(badge).toHaveTextContent("باقة");
    expect(badge).toHaveTextContent("وفّر 15.00 ج.م");
  });

  it("says nothing about a saving when there is none", () => {
    stubApi(signedIn);
    renderWithQuery(<ProductCard product={bundleProduct({ saving: "0.00" })} />);

    expect(screen.getByTestId("bundle-card")).not.toHaveTextContent("وفّر");
  });
});

describe("bundle page", () => {
  beforeEach(() => {
    params.value = { slug: "set-1" };
  });

  it("lists the components with their quantity, unit, link and stock, and names the missing one", async () => {
    stubApi({ ...signedIn, "GET /api/store/products/set-1": json(200, { data: bundleProduct() }) });
    renderWithQuery(<ProductDetail />);

    const list = await screen.findByRole("region", { name: "محتويات الباقة" });
    const math = within(list).getByTestId("bundle-component-BK-002");
    expect(within(math).getByRole("link", { name: "سلاح التلميذ رياضيات" })).toHaveAttribute("href", "/p/selah-math");
    expect(math).toHaveTextContent("2 نسخة");
    expect(math).toHaveTextContent("غير متوفر");
    expect(within(list).getByTestId("bundle-component-BK-001")).toHaveTextContent("متوفر");
    expect(screen.getByTestId("bundle-unavailable")).toHaveTextContent(
      "الباقة غير متاحة الآن: ينقص سلاح التلميذ رياضيات",
    );
    expect(screen.getByText("وفّر 15.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("الأصناف منفردة بـ 150.00 ج.م")).toBeInTheDocument();
  });

  it("says how many bundles can be made when every component is there", async () => {
    const components = bundleProduct().components!.map((component) => ({ ...component, in_stock: true }));
    stubApi({
      ...signedIn,
      "GET /api/store/products/set-1": json(200, { data: bundleProduct({ components, availability: 4 }) }),
    });
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByTestId("bundle-availability")).toHaveTextContent("متاح الآن 4 باقة");
    expect(screen.queryByTestId("bundle-unavailable")).not.toBeInTheDocument();
  });
});

describe("bundle in the cart and the order", () => {
  it("marks a bundle line in the cart", () => {
    stubApi(signedIn);
    renderWithQuery(
      <ul>
        <CartLine item={cartItem({ sku_unit_id: 950, product_name: "طقم أولى إعدادي", is_bundle: true })} />
        <CartLine item={cartItem()} />
      </ul>,
    );

    expect(screen.getByTestId("cart-bundle-950")).toHaveTextContent("باقة");
    expect(screen.queryByTestId("cart-bundle-601")).not.toBeInTheDocument();
  });

  it("shows the bundle line in the shopper's order and opens it to its components", async () => {
    params.value = { number: "1001" };
    const items = [
      orderItem({ id: 70, product_name: "طقم أولى إعدادي", is_bundle: true, unit: "طقم", quantity: 1 }),
      orderItem({
        id: 71,
        product_name: "الأضواء علوم",
        sku_name: "الأضواء علوم",
        unit: "نسخة",
        quantity: 1,
        parent_item_id: 70,
      }),
      orderItem({
        id: 72,
        product_name: "سلاح التلميذ رياضيات",
        sku_name: "سلاح التلميذ رياضيات",
        unit: "نسخة",
        quantity: 2,
        parent_item_id: 70,
      }),
    ];
    stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/orders/1001": json(200, { data: order({ items }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<OrderDetailView />);

    const line = await screen.findByTestId("order-line-70");
    expect(line).toHaveTextContent("باقة");
    expect(screen.queryByTestId("order-line-71")).not.toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /الأصناف/ })).toHaveTextContent("(1 صنف)");

    await user.click(within(line).getByRole("button", { name: "عرض المكونات (2)" }));
    const components = screen.getByRole("list", { name: "مكونات طقم أولى إعدادي" });
    expect(components).toHaveTextContent("الأضواء علوم × 1 نسخة");
    expect(components).toHaveTextContent("سلاح التلميذ رياضيات × 2 نسخة");

    await user.click(within(line).getByRole("button", { name: "إخفاء المكونات" }));
    expect(screen.queryByRole("list", { name: "مكونات طقم أولى إعدادي" })).not.toBeInTheDocument();
  });
});
