import { describe, expect, it } from "vitest";

import { can } from "@/lib/dashboard/permissions";

import { dashboardMe } from "../../dashboard-fixtures";

describe("can (D82)", () => {
  it("is true when the role has any one of the permissions", () => {
    const accountant = dashboardMe({ role: "accountant" });

    expect(can(accountant, "collections.manage")).toBe(true);
    expect(can(accountant, "customers.manage", "customers.credit")).toBe(true);
    expect(can(accountant, "settings.manage")).toBe(false);
  });

  it("allows nothing without a session", () => {
    expect(can(null, "orders.view")).toBe(false);
    expect(can(undefined, "orders.view")).toBe(false);
  });
});
