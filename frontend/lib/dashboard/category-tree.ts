import type { CategoryKind, DashboardCategory } from "@/lib/api/types";

// Pure helpers over the dashboard category tree (08 §6: GET categories returns roots with children).

/**
 * The section kind of a category anywhere in the whole tree, archived nodes included (a product may still sit
 * in an archived category and needs its kind for the price label and the fields, D68). Null when unknown.
 */
export function kindOfCategory(nodes: readonly DashboardCategory[], id: number | null): CategoryKind | null {
  if (id === null) {
    return null;
  }

  for (const node of nodes) {
    if (node.id === id) {
      return node.kind;
    }

    const found = kindOfCategory(node.children, id);

    if (found !== null) {
      return found;
    }
  }

  return null;
}

/** The tree without archived nodes (an archived node hides its subtree too). */
export function activeOnly(nodes: readonly DashboardCategory[]): DashboardCategory[] {
  return nodes
    .filter((node) => node.status === "active")
    .map((node) => ({ ...node, children: activeOnly(node.children) }));
}

export type CategoryRow = { node: DashboardCategory; depth: number; /** Ancestor names, root first. */ path: string[] };

/** Depth-first rows for a parent picker: each node with its depth and ancestors, siblings in sort order. */
export function flatten(nodes: readonly DashboardCategory[], depth = 0, path: string[] = []): CategoryRow[] {
  return [...nodes]
    .sort((a, b) => a.sort_order - b.sort_order)
    .flatMap((node) => [{ node, depth, path }, ...flatten(node.children, depth + 1, [...path, node.name])]);
}

/** Folds the spellings people mix up when typing Arabic (hamza forms, ta marbuta, alef maqsura, diacritics). */
export function searchKey(text: string): string {
  return text
    .replace(/[ً-ْـ]/g, "")
    .replace(/[أإآ]/g, "ا")
    .replace(/ة/g, "ه")
    .replace(/ى/g, "ي")
    .toLowerCase()
    .trim();
}

/** Rows whose name or any ancestor's name contains the query, so "ابتدائي" finds the grades under it too. */
export function searchCategories(rows: readonly CategoryRow[], query: string): CategoryRow[] {
  const needle = searchKey(query);

  if (needle === "") {
    return [...rows];
  }

  return rows.filter((row) => [...row.path, row.node.name].some((name) => searchKey(name).includes(needle)));
}

/** The node's id and every id under it: none of them can become its parent (that would be a cycle). */
export function subtreeIds(node: DashboardCategory): Set<number> {
  return new Set([node.id, ...node.children.flatMap((child) => [...subtreeIds(child)])]);
}

/** Moves `activeId` to the position of `overId` within one sibling list, then numbers the list 0..n. */
export function reorderSiblings(
  siblings: readonly DashboardCategory[],
  activeId: number,
  overId: number,
): { id: number; sort_order: number }[] | null {
  const ordered = [...siblings].sort((a, b) => a.sort_order - b.sort_order);
  const from = ordered.findIndex((node) => node.id === activeId);
  const to = ordered.findIndex((node) => node.id === overId);

  if (from === -1 || to === -1 || from === to) {
    return null;
  }

  const [moved] = ordered.splice(from, 1);

  if (moved === undefined) {
    return null;
  }

  ordered.splice(to, 0, moved);

  return ordered.map((node, index) => ({ id: node.id, sort_order: index }));
}

/** The tree with one sibling list renumbered, for the optimistic update while the reorder saves. */
export function applyOrder(
  nodes: readonly DashboardCategory[],
  order: readonly { id: number; sort_order: number }[],
): DashboardCategory[] {
  const positions = new Map(order.map((entry) => [entry.id, entry.sort_order]));

  return nodes.map((node) => ({
    ...node,
    sort_order: positions.get(node.id) ?? node.sort_order,
    children: applyOrder(node.children, order),
  }));
}
