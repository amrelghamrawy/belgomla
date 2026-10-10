import { describe, expect, it } from "vitest";

import { activeFilters, hasFilters, listSearch, pageOf, readListQuery } from "@/lib/dashboard/list-query";

describe("readListQuery", () => {
  it("keeps the list's own keys, the page and facets, and drops the rest and empty values", () => {
    const params = new URLSearchParams("status=pending&page=2&f[grade]=12&utm=x&q=");

    expect(readListQuery(params, ["status", "q"])).toEqual({ status: "pending", page: "2", "f[grade]": "12" });
  });
});

describe("listSearch", () => {
  it("returns to the first page when a filter changes", () => {
    expect(listSearch({ status: "pending", page: "3" }, { status: "confirmed" })).toBe("?status=confirmed");
  });

  it("keeps the page when the patch is the page", () => {
    expect(listSearch({ status: "pending" }, { page: 2 })).toBe("?page=2&status=pending");
  });

  it("removes keys set to null or empty and drops page 1", () => {
    expect(listSearch({ status: "pending", q: "علوم", page: "2" }, { q: "", status: null, page: 1 })).toBe("");
  });

  it("serialises keys in a stable order so equal filters give equal URLs", () => {
    expect(listSearch({}, { to: "2026-10-10", from: "2026-10-01" })).toBe("?from=2026-10-01&to=2026-10-10");
  });
});

describe("pageOf and hasFilters", () => {
  it("reads the page, falling back to 1", () => {
    expect(pageOf({ page: "4" })).toBe(4);
    expect(pageOf({ page: "x" })).toBe(1);
    expect(pageOf({})).toBe(1);
  });

  it("ignores the page when telling whether filters are set", () => {
    expect(hasFilters({ page: "2" })).toBe(false);
    expect(hasFilters({ q: "علوم" })).toBe(true);
  });
});

describe("activeFilters", () => {
  it("counts the given keys that are set, and facets only when asked", () => {
    const query = { q: "علوم", status: "active", sort: "name", page: "2", "f[grade]": "12" };

    expect(activeFilters(query, ["status", "sort", "low_stock"])).toBe(2);
    expect(activeFilters(query, ["status", "sort"], true)).toBe(3);
    expect(activeFilters({}, ["status"])).toBe(0);
  });
});
