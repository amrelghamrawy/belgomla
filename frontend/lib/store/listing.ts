// What a product list shows, read from and written to the page URL so a selection can be shared (BR-P08, BR-P13).
// Pure functions: the hooks in catalog.ts turn a Listing into API calls.

export const SORTS = ["-created_at", "name", "price", "-price"] as const;

export type Sort = (typeof SORTS)[number];

/** One option id per facet key (`f[grade]=7`); facets are AND-combined, each key takes a single value. */
export type FacetSelection = Record<string, string>;

export type Listing = {
  category?: string;
  q?: string;
  sort?: Sort;
  f: FacetSelection;
};

const FACET_PARAM = /^f\[([^\][]{1,60})\]$/;

function isSort(value: string | null): value is Sort {
  return value !== null && (SORTS as readonly string[]).includes(value);
}

/** Reads `q`, `sort` and `f[key]=value` from the page's search params; unknown sorts are ignored. */
export function parseListing(params: URLSearchParams, category?: string): Listing {
  const f: FacetSelection = {};

  params.forEach((value, key) => {
    const facet = FACET_PARAM.exec(key)?.[1];

    if (facet !== undefined && value !== "") {
      f[facet] = value;
    }
  });

  const q = params.get("q")?.trim() || undefined;
  const sort = params.get("sort");

  return { category, q, sort: isSort(sort) ? sort : undefined, f };
}

/** The page's query string for a listing (the category lives in the path, not here). */
export function listingSearch(listing: Omit<Listing, "category">): string {
  const params = new URLSearchParams();

  if (listing.q !== undefined && listing.q !== "") {
    params.set("q", listing.q);
  }

  if (listing.sort !== undefined) {
    params.set("sort", listing.sort);
  }

  for (const [key, value] of Object.entries(listing.f).sort(([a], [b]) => a.localeCompare(b))) {
    params.set(`f[${key}]`, value);
  }

  const search = params.toString();

  return search === "" ? "" : `?${search}`;
}

/** The printable price list for the same selection (BR-P13); the category travels as `?category=`. */
export function priceListHref(listing: Listing): string {
  const search = listingSearch({ q: listing.q, f: listing.f });

  if (listing.category === undefined) {
    return `/price-list${search}`;
  }

  return `/price-list${search === "" ? "?" : `${search}&`}category=${encodeURIComponent(listing.category)}`;
}

/** Selects `value` for `key`, or clears the key when it is already selected (or `value` is null). */
export function toggleFacet(selection: FacetSelection, key: string, value: string | null): FacetSelection {
  const next = { ...selection };

  if (value === null || next[key] === value) {
    delete next[key];
  } else {
    next[key] = value;
  }

  return next;
}

/** The query sent to `/api/store/products`, `/filters` and `/price-list`. */
export function apiParams(listing: Listing): Record<string, string | undefined | Record<string, readonly string[]>> {
  return {
    category: listing.category,
    q: listing.q,
    sort: listing.sort,
    f: Object.fromEntries(Object.entries(listing.f).map(([key, value]) => [key, [value]])),
  };
}
