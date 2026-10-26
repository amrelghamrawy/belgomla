import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { ProductCard } from "@/components/store/ProductCard";

import { cart, cartItem, comingSoonSku, customer, hiddenPriceSku, storeProduct, storeSku } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { renderWithQuery, router } from "../render";

const guest = { "GET /api/store/me": envelopeError(401, "unauthenticated", "Unauthenticated.") };
const signedIn = (lines = cart({ items: [] })) => ({
  "GET /api/store/me": json(200, { data: customer() }),
  "GET /api/store/cart": json(200, { data: lines }),
});

beforeEach(() => {
  router.push.mockReset();
});

describe("ProductCard", () => {
  it("BR-PR10 shows the wholesale price, the cover price and the retailer's margin", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);

    expect(screen.getByText("90.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("120.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText(/سعر الغلاف:/)).toBeInTheDocument();
    expect(screen.getByText("هامش ربحك 25%")).toBeInTheDocument();
  });

  it("shows the margin as a badge on the cover and keeps the SKU code off the card", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);

    expect(screen.getAllByText("هامش ربحك 25%")).toHaveLength(1);
    expect(screen.queryByText("BK-001")).not.toBeInTheDocument();
  });

  it("shows no margin badge while prices are hidden", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ sku: hiddenPriceSku(), prices_hidden: true })} />);

    expect(screen.queryByText(/هامش ربحك/)).not.toBeInTheDocument();
  });
  it("D68 calls the reference price the retail price outside the books section", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ category: { id: 9, name: "ألعاب", kind: "toys" } })} />);

    expect(screen.getByText(/سعر القطاعي:/)).toBeInTheDocument();
    expect(screen.queryByText(/سعر الغلاف/)).not.toBeInTheDocument();
  });

  it("shows the pack, the MOQ and the first quantity tier", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);

    expect(screen.getByText("كرتونة = 20 نسخة · أقل طلب 2")).toBeInTheDocument();
    expect(screen.getByText("من 10 كرتونة: 85.00 ج.م")).toBeInTheDocument();
  });

  it("D67 draws a generated cover when the product has no photo", () => {
    stubApi(guest);
    renderWithQuery(
      <ProductCard
        product={storeProduct({
          custom_fields: [
            { key: "subject", label: "المادة", value: "علوم" },
            { key: "grade", label: "السنة الدراسية", value: "أولى إعدادي" },
            { key: "publisher", label: "الناشر", value: "الأضواء" },
          ],
        })}
      />,
    );

    const cover = screen.getByTestId("generated-cover");
    expect(within(cover).getByText("علوم")).toBeInTheDocument();
    expect(within(cover).getByText("أولى إعدادي")).toBeInTheDocument();
    expect(within(cover).getByText("الأضواء")).toBeInTheDocument();
  });

  it("shows the photo instead of a generated cover when there is one", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ main_image_url: "/storage/products/11.jpg" })} />);

    expect(screen.queryByTestId("generated-cover")).not.toBeInTheDocument();
  });

  it("D1 shows a login link instead of prices and no add button when the store hides them", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ sku: hiddenPriceSku(), prices_hidden: true })} />);

    const login = screen.getByRole("link", { name: "سجل الدخول لرؤية الأسعار" });
    expect(login).toHaveAttribute(
      "href",
      `/account/login?next=${encodeURIComponent(`/p/${encodeURIComponent(storeProduct().slug)}`)}`,
    );
    expect(screen.queryByText(/ج\.م/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("D21 marks a coming-soon product and cannot be added", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ status: "coming_soon", sku: comingSoonSku() })} />);

    expect(screen.getAllByText("قريبًا").length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "قريبًا" })).toBeDisabled();
  });

  it("marks an active product without stock as unavailable", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct({ sku: storeSku({ available: 0, in_stock: false }) })} />);

    expect(screen.getAllByText("غير متوفر").length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "غير متوفر" })).toBeDisabled();
  });

  it("links to the product page", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);

    expect(screen.getByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toHaveAttribute(
      "href",
      `/p/${encodeURIComponent("الأضواء-علوم-أولى-إعدادي")}`,
    );
  });
});

describe("QuickAdd on the card", () => {
  const addButton = () => screen.getByRole("button", { name: "أضف «الأضواء علوم أولى إعدادي» إلى السلة" });

  it("BR-CU06 sends a guest to log in and back to this page, filters included", async () => {
    window.history.pushState({}, "", "/c/prep?sort=name");
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);
    await waitFor(() => expect(addButton()).toBeEnabled());

    await userEvent.click(addButton());

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/account/login?next=%2Fc%2Fprep%3Fsort%3Dname"));
  });

  it("BR-MOQ01 adds the MOQ in one tap", async () => {
    const fetchMock = stubApi({
      ...signedIn(),
      "POST /api/store/cart/items": json(200, { data: cart({ items: [cartItem({ quantity: 2 })] }) }),
    });
    renderWithQuery(<ProductCard product={storeProduct()} />);
    expect(addButton()).toHaveTextContent("أضف 2 كرتونة");
    await waitFor(() => expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(1));

    await userEvent.click(addButton());

    expect(await screen.findByRole("group", { name: "2 كرتونة في السلة" })).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({ sku_id: 501, quantity: 2 });
  });

  it("shows the cart line with the server's line total and raises it by one", async () => {
    const fetchMock = stubApi({
      ...signedIn(cart({ items: [cartItem({ quantity: 6, line_total: "540.00" })] })),
      "PATCH /api/store/cart/items/501": json(200, { data: cart({ items: [cartItem({ quantity: 7 })] }) }),
    });
    renderWithQuery(<ProductCard product={storeProduct()} />);
    const line = await screen.findByRole("group", { name: "6 كرتونة في السلة" });
    expect(within(line).getByText("540.00 ج.م")).toBeInTheDocument();

    await userEvent.click(within(line).getByRole("button", { name: "زيادة الكمية" }));

    await screen.findByRole("group", { name: "7 كرتونة في السلة" });
    expect(callsTo(fetchMock, "PATCH /api/store/cart/items/501")[0]?.body).toEqual({ quantity: 7 });
  });

  it("BR-MOQ01 removes the line instead of going below the MOQ", async () => {
    const fetchMock = stubApi({
      ...signedIn(cart({ items: [cartItem({ quantity: 2 })] })),
      "DELETE /api/store/cart/items/501": json(200, { data: cart({ items: [] }) }),
    });
    renderWithQuery(<ProductCard product={storeProduct()} />);
    const line = await screen.findByRole("group", { name: "2 كرتونة في السلة" });

    await userEvent.click(within(line).getByRole("button", { name: "حذف من السلة" }));

    await waitFor(() => expect(addButton()).toBeInTheDocument());
    expect(callsTo(fetchMock, "DELETE /api/store/cart/items/501")).toHaveLength(1);
  });
});

describe("pre-orders on the card (BR-O14)", () => {
  const preorderable = (remaining: number | null = null) =>
    storeProduct({
      status: "coming_soon",
      sku: comingSoonSku(),
      preorder: { enabled: true, expected_at: "2026-12-01", remaining },
    });
  const bookButton = () => screen.getByRole("button", { name: "احجز «الأضواء علوم أولى إعدادي»" });

  it("shows the expected day and that the final price is set when it arrives", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={preorderable()} />);

    expect(screen.getByTestId("preorder-note")).toHaveTextContent("متوقع 1 ديسمبر 2026 · السعر النهائي عند التوفر");
  });

  it("a coming soon product without pre-orders still cannot be added", () => {
    stubApi(guest);
    renderWithQuery(
      <ProductCard
        product={storeProduct({
          status: "coming_soon",
          sku: comingSoonSku(),
          preorder: { enabled: false, expected_at: null, remaining: null },
        })}
      />,
    );

    expect(screen.getByRole("button", { name: "قريبًا" })).toBeDisabled();
    expect(screen.queryByTestId("preorder-note")).not.toBeInTheDocument();
  });

  it("books the MOQ as a pre-order line in one tap", async () => {
    const fetchMock = stubApi({
      ...signedIn(),
      "POST /api/store/cart/items": json(200, {
        data: cart({
          items: [],
          preorders: {
            items: [{ ...cartItem({ quantity: 2, available: 0 }), expected_at: "2026-12-01" }],
            items_total: "180.00",
          },
        }),
      }),
    });
    renderWithQuery(<ProductCard product={preorderable()} />);
    await waitFor(() => expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(1));
    expect(bookButton()).toHaveTextContent("احجز 2 كرتونة");

    await userEvent.click(bookButton());

    expect(await screen.findByRole("group", { name: "محجوز 2 كرتونة" })).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({
      sku_id: 501,
      quantity: 2,
      kind: "preorder",
    });
  });

  it("changes and removes the pre-order line by its kind", async () => {
    const booked = cart({
      items: [],
      preorders: { items: [{ ...cartItem({ quantity: 2, available: 0 }), expected_at: null }], items_total: "180.00" },
    });
    const fetchMock = stubApi({
      ...signedIn(booked),
      "PATCH /api/store/cart/items/501": json(200, { data: booked }),
      "DELETE /api/store/cart/items/501": json(200, { data: cart({ items: [] }) }),
    });
    renderWithQuery(<ProductCard product={preorderable()} />);
    const line = await screen.findByRole("group", { name: "محجوز 2 كرتونة" });

    await userEvent.click(within(line).getByRole("button", { name: "زيادة الكمية" }));
    await userEvent.click(within(line).getByRole("button", { name: "حذف من السلة" }));

    await waitFor(() => expect(callsTo(fetchMock, "DELETE /api/store/cart/items/501")).toHaveLength(1));
    expect(callsTo(fetchMock, "PATCH /api/store/cart/items/501")[0]?.body).toEqual({ quantity: 3, kind: "preorder" });
    expect(callsTo(fetchMock, "DELETE /api/store/cart/items/501")[0]?.url.searchParams.get("kind")).toBe("preorder");
  });

  it("is full when nothing remains under the merchant's cap", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={preorderable(0)} />);

    expect(screen.getByRole("button", { name: "اكتمل الحجز" })).toBeDisabled();
  });
});

describe("the customer's own price on the card (BR-PR14)", () => {
  it("marks a group or negotiated price «سعرك الخاص»", () => {
    stubApi(guest);
    renderWithQuery(
      <ProductCard product={storeProduct({ sku: storeSku({ base_price: "85.00", price_source: "group" }) })} />,
    );

    expect(screen.getByText("85.00 ج.م")).toBeInTheDocument();
    expect(screen.getByTestId("your-price")).toHaveTextContent("سعرك الخاص");
  });

  it("shows no badge for the store's normal price", () => {
    stubApi(guest);
    renderWithQuery(<ProductCard product={storeProduct()} />);

    expect(screen.queryByTestId("your-price")).toBeNull();
  });
});
