// Every TanStack Query key lives here (rules/03 §4), grouped by surface.
export const queryKeys = {
  tenant: ["store", "tenant"] as const,
  customerSession: ["store", "me"] as const,
  dashboardSession: ["dashboard", "me"] as const,
};
