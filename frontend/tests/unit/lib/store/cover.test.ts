import { describe, expect, it } from "vitest";

import { COVER_LAYOUTS, COVER_TONES, coverFor, hashText, monogram } from "@/lib/store/cover";

import { storeProduct } from "../../fixtures";

const book = (subject: string, publisher: string) =>
  storeProduct({
    custom_fields: [
      { key: "subject", label: "المادة", value: subject },
      { key: "grade", label: "السنة الدراسية", value: "أولى إعدادي" },
      { key: "publisher", label: "الناشر", value: publisher },
      { key: "term", label: "الترم", value: "الترم الأول" },
    ],
  });

describe("hashText", () => {
  it("returns the same number for the same text", () => {
    expect(hashText("رياضيات")).toBe(hashText("رياضيات"));
  });

  it("gives the five usual subjects five different tones", () => {
    const tones = ["عربي", "إنجليزي", "رياضيات", "علوم", "دراسات"].map((subject) => hashText(subject) % COVER_TONES);

    expect(new Set(tones).size).toBe(5);
  });

  it("gives the three big publishers three different layouts", () => {
    const layouts = ["الأضواء", "المعلم", "سلاح التلميذ"].map((publisher) => hashText(publisher) % COVER_LAYOUTS);

    expect(new Set(layouts).size).toBe(3);
  });
});

describe("coverFor", () => {
  it("D67 lays out a book as subject, grade, publisher and term", () => {
    expect(coverFor(book("رياضيات", "المعلم"))).toMatchObject({
      title: "رياضيات",
      subtitle: "أولى إعدادي",
      badge: "المعلم",
      note: "الترم الأول",
      compact: false,
    });
  });

  it("keeps one tone per subject and one layout per publisher", () => {
    const first = coverFor(book("علوم", "الأضواء"));
    const second = coverFor(book("علوم", "الأضواء"));

    expect(second.tone).toBe(first.tone);
    expect(second.layout).toBe(first.layout);
  });

  it("falls back to the category and the product name without book fields", () => {
    const cover = coverFor(
      storeProduct({ name: "كشكول 100 ورقة", custom_fields: [], category: { id: 9, name: "كشاكيل" } }),
    );

    expect(cover).toMatchObject({ title: "كشاكيل", subtitle: "كشكول 100 ورقة", badge: null, note: null });
  });

  it("uses the product name alone, set smaller, without a category", () => {
    const cover = coverFor(storeProduct({ name: "أقلام رصاص فابر كاستل", custom_fields: [], category: null }));

    expect(cover).toMatchObject({ title: "أقلام رصاص فابر كاستل", subtitle: null, compact: true });
  });
});

describe("monogram", () => {
  it.each([
    ["مكتبة النور", "ن"],
    ["دار المعرفة", "م"],
    ["الرواد", "ر"],
    ["Book Hub", "H"],
    ["", ""],
  ])("takes the initial of %s", (name, letter) => {
    expect(monogram(name)).toBe(letter);
  });
});
