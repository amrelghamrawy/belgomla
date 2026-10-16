import { describe, expect, it } from "vitest";

import { insertAt, invitationBody, renderInvitation, unknownPlaceholders } from "@/lib/dashboard/invitation-message";

describe("invitation message", () => {
  it("fills every placeholder and drops trailing blanks", () => {
    expect(
      renderInvitation("{اسم_المتجر} يرحب بـ {اسم_العميل} ({عدد_الأيام}) — {اسم_المتجر}\n\n", {
        customer: "أحمد",
        store: "النور",
        days: 7,
      }),
    ).toBe("النور يرحب بـ أحمد (7) — النور");
  });

  it("lists unknown placeholders once each and ignores known ones", () => {
    expect(unknownPlaceholders("{الاسم} {اسم_العميل} {الاسم} {link} {}")).toEqual(["{الاسم}", "{link}", "{}"]);
    expect(unknownPlaceholders("أهلًا {اسم_العميل}")).toEqual([]);
  });

  it("sends null for an empty box or the default, the trimmed text otherwise", () => {
    expect(invitationBody("  ", "افتراضي")).toBeNull();
    expect(invitationBody("افتراضي ", "افتراضي")).toBeNull();
    expect(invitationBody(" نص خاص ", "افتراضي")).toBe("نص خاص");
  });

  it("replaces the selection with the token and puts the caret after it", () => {
    expect(insertAt("Hi XX!", "{T}", 3, 5)).toEqual({ text: "Hi {T}!", caret: 6 });
  });
});
