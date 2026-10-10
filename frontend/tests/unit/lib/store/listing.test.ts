import { describe, expect, it } from "vitest";

import { apiParams, listingSearch, parseListing, priceListHref, toggleFacet } from "@/lib/store/listing";

describe("parseListing", () => {
  it("BR-P08 reads the facet selection, search and sort from the URL", () => {
    const listing = parseListing(new URLSearchParams("f[grade]=7&f[publisher]=29&q=علوم&sort=price"), "prep");

    expect(listing).toEqual({ category: "prep", q: "علوم", sort: "price", f: { grade: "7", publisher: "29" } });
  });

  it("ignores an unknown sort, an empty search and empty facet values", () => {
    expect(parseListing(new URLSearchParams("sort=popular&q=%20&f[grade]="))).toEqual({
      category: undefined,
      q: undefined,
      sort: undefined,
      f: {},
    });
  });

  it("ignores parameters that are not facets", () => {
    expect(parseListing(new URLSearchParams("page=2&f=7&category=x")).f).toEqual({});
  });
});

describe("listingSearch", () => {
  it("BR-P08 writes a shareable query string with the facets in a stable order", () => {
    expect(listingSearch({ q: "علوم", sort: "-price", f: { subject: "15", grade: "7" } })).toBe(
      "?q=%D8%B9%D9%84%D9%88%D9%85&sort=-price&f%5Bgrade%5D=7&f%5Bsubject%5D=15",
    );
  });

  it("is empty without a selection", () => {
    expect(listingSearch({ f: {} })).toBe("");
  });

  it("round-trips through parseListing", () => {
    const listing = { category: undefined, q: "رياضيات", sort: "name" as const, f: { grade: "7" } };

    expect(parseListing(new URLSearchParams(listingSearch(listing)))).toEqual(listing);
  });
});

describe("toggleFacet", () => {
  it("selects an option, replacing the facet's previous value (one value per facet)", () => {
    expect(toggleFacet({ grade: "7" }, "grade", "8")).toEqual({ grade: "8" });
  });

  it("AND-combines facets: selecting one keeps the others", () => {
    expect(toggleFacet({ grade: "7" }, "subject", "15")).toEqual({ grade: "7", subject: "15" });
  });

  it.each([
    ["the selected option again", "7"],
    ["null (the all option)", null],
  ])("clears the facet when given %s", (_, value) => {
    expect(toggleFacet({ grade: "7", subject: "15" }, "grade", value)).toEqual({ subject: "15" });
  });
});

describe("priceListHref", () => {
  it("BR-P13 carries the category and facets, not the sort", () => {
    expect(priceListHref({ category: "prep", sort: "price", f: { grade: "7" } })).toBe(
      "/price-list?f%5Bgrade%5D=7&category=prep",
    );
  });

  it("works without a selection", () => {
    expect(priceListHref({ f: {} })).toBe("/price-list");
  });
});

describe("apiParams", () => {
  it("sends each facet as f[key]", () => {
    expect(apiParams({ category: "prep", q: "x", sort: "name", f: { grade: "7" } })).toEqual({
      category: "prep",
      q: "x",
      sort: "name",
      f: { grade: ["7"] },
    });
  });
});
