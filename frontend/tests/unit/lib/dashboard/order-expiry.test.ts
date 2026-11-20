import { describe, expect, it } from "vitest";

import { expiryLeft } from "@/lib/dashboard/order-expiry";

const now = Date.parse("2026-10-19T08:00:00Z");

describe("expiryLeft (D81)", () => {
  it("splits what is left into hours and minutes, rounding up to the minute", () => {
    expect(expiryLeft("2026-10-19T13:29:30Z", now)).toEqual({ hours: 5, minutes: 30 });
    expect(expiryLeft("2026-10-19T08:00:01Z", now)).toEqual({ hours: 0, minutes: 1 });
    expect(expiryLeft("2026-10-21T08:00:00Z", now)).toEqual({ hours: 48, minutes: 0 });
  });

  it("stops at zero once the time has passed (the next run cancels the order)", () => {
    expect(expiryLeft("2026-10-19T07:00:00Z", now)).toEqual({ hours: 0, minutes: 0 });
  });
});
