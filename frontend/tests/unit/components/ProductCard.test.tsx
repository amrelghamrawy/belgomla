import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { ProductCard } from "@/components/store/ProductCard";

import { comingSoonSku, hiddenPriceSku, storeProduct, storeSku } from "../fixtures";

describe("ProductCard", () => {
  it("BR-PR10 shows the wholesale price, the cover price and the discount", () => {
    render(<ProductCard product={storeProduct()} />);

    expect(screen.getByText("90.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("120.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("خصم 25%")).toBeInTheDocument();
  });

  it("D1 shows a login link instead of prices when the store hides them", () => {
    render(<ProductCard product={storeProduct({ sku: hiddenPriceSku(), prices_hidden: true })} />);

    const login = screen.getByRole("link", { name: "سجل الدخول لرؤية الأسعار" });
    expect(login).toHaveAttribute(
      "href",
      `/account/login?next=${encodeURIComponent(`/p/${encodeURIComponent(storeProduct().slug)}`)}`,
    );
    expect(screen.queryByText(/ج\.م/)).not.toBeInTheDocument();
  });

  it("D21 marks a coming-soon product", () => {
    render(<ProductCard product={storeProduct({ status: "coming_soon", sku: comingSoonSku() })} />);

    expect(screen.getAllByText("قريبًا").length).toBeGreaterThan(0);
  });

  it("marks an active product without stock as unavailable", () => {
    render(<ProductCard product={storeProduct({ sku: storeSku({ available: 0, in_stock: false }) })} />);

    expect(screen.getByText("غير متوفر")).toBeInTheDocument();
  });

  it("links to the product page", () => {
    render(<ProductCard product={storeProduct()} />);

    expect(screen.getByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toHaveAttribute(
      "href",
      `/p/${encodeURIComponent("الأضواء-علوم-أولى-إعدادي")}`,
    );
  });
});
