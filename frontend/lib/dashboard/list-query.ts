// URL state for dashboard lists (rules/03 §2: filters, sort, search and page live in the URL). Pure functions,
// so every list parses and serialises its query the same way; `useListQuery` wires them to the router.

/** A list's query as flat strings: `{ status: "pending", page: "2", "f[grade]": "12" }`. */
export type ListQuery = Record<string, string>;

/** D43: 24 rows per page, the backend's PaginatedRequest default. */
export const PAGE_SIZE = 24;

/** The params a list understands: its own keys, `page`, and any facet `f[...]`. Empty values are dropped. */
export function readListQuery(params: URLSearchParams, keys: readonly string[]): ListQuery {
  const query: ListQuery = {};

  params.forEach((value, key) => {
    if (value !== "" && (key === "page" || keys.includes(key) || /^f\[[^\]]+\]$/.test(key))) {
      query[key] = value;
    }
  });

  return query;
}

/**
 * The search string after applying `patch` (`null` or "" removes a key). Changing any filter returns to the
 * first page, because page 3 of the old result means nothing for the new one; a patch with `page` keeps it.
 */
export function listSearch(current: ListQuery, patch: Record<string, string | number | null | undefined>): string {
  const next: ListQuery = { ...current };

  for (const [key, value] of Object.entries(patch)) {
    if (value === null || value === undefined || value === "") {
      delete next[key];
    } else {
      next[key] = String(value);
    }
  }

  if (!("page" in patch) || next.page === "1") {
    delete next.page;
  }

  const search = new URLSearchParams(Object.entries(next).sort(([a], [b]) => a.localeCompare(b))).toString();

  return search === "" ? "" : `?${search}`;
}

/** The current page number, 1 when absent or invalid. */
export function pageOf(query: ListQuery): number {
  const page = Number(query.page);

  return Number.isInteger(page) && page > 0 ? page : 1;
}

/** Whether any filter (anything but the page) is set. */
export function hasFilters(query: ListQuery): boolean {
  return Object.keys(query).some((key) => key !== "page");
}
