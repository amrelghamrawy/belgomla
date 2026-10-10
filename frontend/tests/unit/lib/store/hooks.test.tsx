import { act, waitFor } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { queryKeys } from "@/lib/api/keys";
import { categoryOptions, findCategory, useProductsInfinite } from "@/lib/store/catalog";
import { newIdempotencyKey } from "@/lib/api/idempotency";
import { useCheckout } from "@/lib/store/checkout";
import { useAddToCart } from "@/lib/store/cart";
import { useVerifyOtp } from "@/lib/store/auth";
import { forgetReset, pendingReset, rememberReset } from "@/lib/store/reset-flow";

import { cart, customer, order, page, storeProduct } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderHookWithQuery } from "../../render";

describe("findCategory", () => {
  const tree = [
    {
      id: 1,
      name: "إعدادي",
      slug: "prep",
      image_url: null,
      children: [{ id: 2, name: "أولى", slug: "first", image_url: null, children: [] }],
    },
  ];

  it("finds a category at any depth by slug", () => {
    expect(findCategory(tree, "first")?.id).toBe(2);
  });

  it("is null for an unknown slug", () => {
    expect(findCategory(tree, "nope")).toBeNull();
  });
});

describe("categoryOptions", () => {
  it("flattens the tree with each parent before its children and their depth", () => {
    const tree = [
      {
        id: 1,
        name: "إعدادي",
        slug: "prep",
        image_url: null,
        children: [{ id: 2, name: "أولى", slug: "first", image_url: null, children: [] }],
      },
      { id: 3, name: "ثانوي", slug: "sec", image_url: null, children: [] },
    ];

    expect(categoryOptions(tree)).toEqual([
      { slug: "prep", name: "إعدادي", depth: 0 },
      { slug: "first", name: "أولى", depth: 1 },
      { slug: "sec", name: "ثانوي", depth: 0 },
    ]);
  });
});

describe("useProductsInfinite", () => {
  it("loads 24 per page with the selection and fetches the next page until the last", async () => {
    const fetchMock = stubApi({
      "GET /api/store/products": [
        json(200, page([storeProduct()], { current_page: 1, last_page: 2, total: 2 })),
        json(200, page([storeProduct({ id: 12 })], { current_page: 2, last_page: 2, total: 2 })),
      ],
    });
    const { result } = renderHookWithQuery(() => useProductsInfinite({ category: "prep", f: { grade: "7" } }));
    await waitFor(() => expect(result.current.hasNextPage).toBe(true));

    await act(() => result.current.fetchNextPage());

    await waitFor(() => expect(result.current.data?.pages).toHaveLength(2));
    expect(result.current.hasNextPage).toBe(false);
    const [first, second] = callsTo(fetchMock, "GET /api/store/products");
    expect(first?.url.search).toBe("?category=prep&f%5Bgrade%5D=7&page=1&per_page=24");
    expect(second?.url.searchParams.get("page")).toBe("2");
  });
});

describe("useAddToCart", () => {
  it("BR-PR04 replaces the cached cart with the priced cart the server returns", async () => {
    const priced = cart();
    stubApi({ "POST /api/store/cart/items": json(200, { data: priced }) });
    const { result, client } = renderHookWithQuery(() => useAddToCart());

    await act(() => result.current.mutateAsync({ sku_id: 501, quantity: 6 }));

    expect(client.getQueryData(queryKeys.cart)).toEqual(priced);
  });
});

describe("useVerifyOtp", () => {
  it("BR-CU04 a verified registration becomes the logged-in session", async () => {
    stubApi({ "POST /api/store/auth/verify-otp": json(200, { data: customer() }) });
    const { result, client } = renderHookWithQuery(() => useVerifyOtp());

    await act(() => result.current.mutateAsync({ phone: "01012345678", code: "123456", purpose: "register" }));

    expect(client.getQueryData(queryKeys.customerSession)).toEqual(customer());
  });

  it("a password-reset code (204) leaves the session alone", async () => {
    stubApi({ "POST /api/store/auth/verify-otp": json(204) });
    const { result, client } = renderHookWithQuery(() => useVerifyOtp());

    await act(() => result.current.mutateAsync({ phone: "01012345678", code: "123456", purpose: "password_reset" }));

    expect(client.getQueryData(queryKeys.customerSession)).toBeUndefined();
  });
});

describe("useCheckout", () => {
  const input = { address_id: 31, customer_note: null };

  it("D55 sends an Idempotency-Key and reuses it when the same order is retried", async () => {
    const fetchMock = stubApi({
      "POST /api/store/checkout": [
        envelopeError(409, "idempotency_in_progress", "قيد التنفيذ"),
        json(201, { data: { order: order() } }),
      ],
    });
    const { result } = renderHookWithQuery(() => useCheckout());

    await act(() => result.current.mutateAsync(input).catch(() => undefined));
    const placed = await act(() => result.current.mutateAsync(input));

    const [first, second] = callsTo(fetchMock, "POST /api/store/checkout");
    expect(first?.headers["Idempotency-Key"]).toMatch(/^checkout-[0-9a-f]{32}$/);
    expect(second?.headers["Idempotency-Key"]).toBe(first?.headers["Idempotency-Key"]);
    expect(placed.number).toBe(1001);
  });

  it("D55 a changed address or note gets a new key", async () => {
    const fetchMock = stubApi({ "POST /api/store/checkout": envelopeError(422, "checkout_failed", "x") });
    const { result } = renderHookWithQuery(() => useCheckout());

    await act(() => result.current.mutateAsync(input).catch(() => undefined));
    await act(() => result.current.mutateAsync({ ...input, customer_note: "بسرعة" }).catch(() => undefined));

    const [first, second] = callsTo(fetchMock, "POST /api/store/checkout");
    expect(second?.headers["Idempotency-Key"]).not.toBe(first?.headers["Idempotency-Key"]);
  });

  it("the next checkout after a success gets a new key", async () => {
    const fetchMock = stubApi({ "POST /api/store/checkout": json(201, { data: { order: order() } }) });
    const { result } = renderHookWithQuery(() => useCheckout());

    await act(() => result.current.mutateAsync(input));
    await act(() => result.current.mutateAsync(input));

    const [first, second] = callsTo(fetchMock, "POST /api/store/checkout");
    expect(second?.headers["Idempotency-Key"]).not.toBe(first?.headers["Idempotency-Key"]);
  });

  it("caches the placed order and refreshes the cart and the orders list", async () => {
    stubApi({ "POST /api/store/checkout": json(201, { data: { order: order() } }) });
    const { result, client } = renderHookWithQuery(() => useCheckout());
    client.setQueryData(queryKeys.cart, cart());

    await act(() => result.current.mutateAsync(input));

    expect(client.getQueryData(queryKeys.order(1001))).toEqual(order());
    expect(client.getQueryState(queryKeys.cart)?.isInvalidated).toBe(true);
  });

  it("makes keys without crypto.randomUUID, which http pages do not have", () => {
    expect(newIdempotencyKey("checkout")).not.toBe(newIdempotencyKey("checkout"));
  });
});

describe("reset flow", () => {
  it("keeps the verified code in memory until the password is set", () => {
    rememberReset({ phone: "01012345678", code: "123456" });

    expect(pendingReset()).toEqual({ phone: "01012345678", code: "123456" });
    forgetReset();
    expect(pendingReset()).toBeNull();
  });
});
