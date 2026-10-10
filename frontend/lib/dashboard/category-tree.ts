import type { DashboardCategory } from "@/lib/api/types";

// Pure helpers over the dashboard category tree (08 §6: GET categories returns roots with children).

/** The tree without archived nodes (an archived node hides its subtree too). */
export function activeOnly(nodes: readonly DashboardCategory[]): DashboardCategory[] {
  return nodes
    .filter((node) => node.status === "active")
    .map((node) => ({ ...node, children: activeOnly(node.children) }));
}

/** Depth-first rows for a parent picker: each node with its depth, siblings in sort order. */
export function flatten(nodes: readonly DashboardCategory[], depth = 0): { node: DashboardCategory; depth: number }[] {
  return [...nodes]
    .sort((a, b) => a.sort_order - b.sort_order)
    .flatMap((node) => [{ node, depth }, ...flatten(node.children, depth + 1)]);
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
