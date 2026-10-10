import { describe, expect, it } from "vitest";

import type { OrderStatus } from "@/lib/api/types";
import { allowsAddressEdit, canCancel, canCollect, canTransition, nextSteps } from "@/lib/dashboard/order-status";
import { balanceOf } from "@/lib/dashboard/orders";

const ALL: OrderStatus[] = ["pending", "confirmed", "preparing", "out_for_delivery", "delivered", "cancelled"];

describe("canTransition", () => {
  // The backend's OrderStatus::canTransitionTo, row by row (BR-O01): forward only, skipping allowed,
  // cancelled from every non-terminal status, nothing out of delivered or cancelled.
  const allowed: Record<OrderStatus, OrderStatus[]> = {
    pending: ["confirmed", "preparing", "out_for_delivery", "delivered", "cancelled"],
    confirmed: ["preparing", "out_for_delivery", "delivered", "cancelled"],
    preparing: ["out_for_delivery", "delivered", "cancelled"],
    out_for_delivery: ["delivered", "cancelled"],
    delivered: [],
    cancelled: [],
  };

  it.each(ALL)("BR-O01 from %s allows exactly the documented targets", (from) => {
    expect(ALL.filter((to) => canTransition(from, to))).toEqual(allowed[from]);
  });
});

describe("nextSteps and the other guards", () => {
  it("lists the forward buttons in path order, cancel apart", () => {
    expect(nextSteps("confirmed")).toEqual(["preparing", "out_for_delivery", "delivered"]);
    expect(nextSteps("delivered")).toEqual([]);
  });

  it("BR-O02 lets the merchant cancel anything but delivered and cancelled", () => {
    expect(ALL.filter(canCancel)).toEqual(["pending", "confirmed", "preparing", "out_for_delivery"]);
  });

  it("D9 allows address edits until out for delivery", () => {
    expect(ALL.filter(allowsAddressEdit)).toEqual(["pending", "confirmed", "preparing"]);
  });

  it("BR-C04 collects at any status except cancelled", () => {
    expect(ALL.filter(canCollect)).toEqual(["pending", "confirmed", "preparing", "out_for_delivery", "delivered"]);
  });

  it("works out the balance in piasters and never below zero", () => {
    expect(balanceOf({ order_total: "450.10", collected_amount: "100.05" })).toBe("350.05");
    expect(balanceOf({ order_total: "100.00", collected_amount: "100.00" })).toBe("0.00");
  });
});
