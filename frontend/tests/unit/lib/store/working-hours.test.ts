import { describe, expect, it } from "vitest";

import { cairoClock, groupWorkingHours, openStatus } from "@/lib/store/working-hours";

const nineToNine = { from: "09:00", to: "21:00" };
const weekdays = [0, 1, 2, 3, 4, 6].map((day) => ({ day, ...nineToNine }));

describe("groupWorkingHours", () => {
  it("starts the week on Saturday and folds neighbouring days with the same hours", () => {
    expect(groupWorkingHours(weekdays)).toEqual([
      { days: [6, 0, 1, 2, 3, 4], ranges: [nineToNine] },
      { days: [5], ranges: [] },
    ]);
  });

  it("splits days whose hours differ and keeps a day's ranges in time order", () => {
    const groups = groupWorkingHours([
      { day: 6, from: "17:00", to: "22:00" },
      { day: 6, from: "09:00", to: "13:00" },
      { day: 0, ...nineToNine },
    ]);

    expect(groups[0]).toEqual({
      days: [6],
      ranges: [
        { from: "09:00", to: "13:00" },
        { from: "17:00", to: "22:00" },
      ],
    });
    expect(groups[1]).toEqual({ days: [0], ranges: [nineToNine] });
    expect(groups[2]).toEqual({ days: [1, 2, 3, 4, 5], ranges: [] });
  });

  it("returns one closed row for an empty schedule", () => {
    expect(groupWorkingHours([])).toEqual([{ days: [6, 0, 1, 2, 3, 4, 5], ranges: [] }]);
  });
});

describe("cairoClock", () => {
  it("reads the weekday and minute on Cairo's wall clock", () => {
    // Thursday 2026-01-15 22:30 UTC is Friday 00:30 in Cairo (UTC+2 in winter).
    expect(cairoClock(new Date("2026-01-15T22:30:00Z"))).toEqual({ day: 5, minutes: 30 });
  });
});

describe("openStatus", () => {
  it("is open inside a range and says when it closes", () => {
    expect(openStatus(weekdays, { day: 0, minutes: 10 * 60 })).toEqual({ open: true, closesAt: "21:00" });
  });

  it("is closed at closing time and opens the next morning", () => {
    expect(openStatus(weekdays, { day: 0, minutes: 21 * 60 })).toEqual({
      open: false,
      opensAt: { day: 1, from: "09:00", inDays: 1 },
    });
  });

  it("opens later today when it is still early", () => {
    expect(openStatus(weekdays, { day: 0, minutes: 8 * 60 })).toEqual({
      open: false,
      opensAt: { day: 0, from: "09:00", inDays: 0 },
    });
  });

  it("skips closed days when looking for the next opening", () => {
    expect(openStatus(weekdays, { day: 4, minutes: 22 * 60 })).toEqual({
      open: false,
      opensAt: { day: 6, from: "09:00", inDays: 2 },
    });
  });

  it("opens again on the same weekday next week when that is the only open day", () => {
    expect(openStatus([{ day: 2, ...nineToNine }], { day: 2, minutes: 22 * 60 })).toEqual({
      open: false,
      opensAt: { day: 2, from: "09:00", inDays: 7 },
    });
  });

  it("stays open past midnight for a range that ends the next day", () => {
    const late = [{ day: 4, from: "20:00", to: "02:00" }];

    expect(openStatus(late, { day: 4, minutes: 23 * 60 })).toEqual({ open: true, closesAt: "02:00" });
    expect(openStatus(late, { day: 5, minutes: 60 })).toEqual({ open: true, closesAt: "02:00" });
    expect(openStatus(late, { day: 5, minutes: 3 * 60 }).open).toBe(false);
  });

  it("never opens with no hours at all", () => {
    expect(openStatus([], { day: 0, minutes: 600 })).toEqual({ open: false, opensAt: null });
  });
});
