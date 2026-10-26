import { describe, expect, it } from "vitest";

import { ar } from "@/lib/copy/ar";
import { timeLeft } from "@/lib/store/offer";

const ends = "2026-11-30T22:00:00+00:00";
const at = (iso: string) => new Date(iso);

describe("timeLeft (BR-PR15)", () => {
  it("counts whole days from two days on", () => {
    expect(timeLeft(ends, at("2026-11-27T21:00:00Z"))).toEqual({ unit: "days", value: 3 });
    expect(timeLeft(ends, at("2026-11-28T22:00:00Z"))).toEqual({ unit: "days", value: 2 });
  });

  it("counts whole hours under two days and from an hour on", () => {
    expect(timeLeft(ends, at("2026-11-28T22:00:01Z"))).toEqual({ unit: "hours", value: 47 });
    expect(timeLeft(ends, at("2026-11-30T21:00:00Z"))).toEqual({ unit: "hours", value: 1 });
  });

  it("counts minutes in the last hour, rounding up the last seconds", () => {
    expect(timeLeft(ends, at("2026-11-30T21:00:01Z"))).toEqual({ unit: "minutes", value: 60 });
    expect(timeLeft(ends, at("2026-11-30T21:59:30Z"))).toEqual({ unit: "minutes", value: 1 });
  });

  it("is null once the offer ended, or for a date it cannot read", () => {
    expect(timeLeft(ends, at("2026-11-30T22:00:00Z"))).toBeNull();
    expect(timeLeft("غدًا", at("2026-11-30T22:00:00Z"))).toBeNull();
  });
});

describe("the offer copy", () => {
  it("counts in Arabic: one, two, a few, many", () => {
    expect(ar.product.offerEndsIn({ unit: "days", value: 1 })).toBe("ينتهي خلال يوم");
    expect(ar.product.offerEndsIn({ unit: "days", value: 2 })).toBe("ينتهي خلال يومين");
    expect(ar.product.offerEndsIn({ unit: "days", value: 3 })).toBe("ينتهي خلال 3 أيام");
    expect(ar.product.offerEndsIn({ unit: "days", value: 14 })).toBe("ينتهي خلال 14 يومًا");
    expect(ar.product.offerEndsIn({ unit: "hours", value: 5 })).toBe("ينتهي خلال 5 ساعات");
    expect(ar.product.offerEndsIn({ unit: "minutes", value: 2 })).toBe("ينتهي خلال دقيقتين");
    expect(ar.product.offer("15")).toBe("عرض 15%");
  });
});
