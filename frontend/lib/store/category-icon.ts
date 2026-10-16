import type { CategoryIcon, CategoryKind } from "@/lib/api/types";

// The icon on a category tile (D73): the one the merchant picked, otherwise a guess from the category's name, then
// its subcategories' names, then its section kind. Pure, so a category always gets the same icon.

/** Every icon a merchant can pick, in the order the dashboard picker shows them. Mirrors the CategoryIcon enum. */
export const CATEGORY_ICONS = [
  "book-open",
  "library",
  "graduation-cap",
  "notebook-pen",
  "pencil",
  "pen",
  "highlighter",
  "eraser",
  "ruler",
  "scissors",
  "palette",
  "brush",
  "calculator",
  "backpack",
  "folder",
  "paperclip",
  "file-text",
  "printer",
  "flask-conical",
  "globe",
  "languages",
  "sigma",
  "puzzle",
  "gamepad-2",
  "shapes",
  "baby",
  "gift",
  "shirt",
  "laptop",
  "package",
] as const satisfies readonly CategoryIcon[];

const BY_KIND: Record<CategoryKind, CategoryIcon> = {
  books: "book-open",
  stationery: "pencil",
  toys: "puzzle",
  general: "package",
};

/**
 * Words that point to an icon, checked in order (the first hit wins), written as `normalize()` leaves them.
 * Specific items come before broad ones: "أقلام ألوان" is colours, "كتب أطفال" is books.
 */
const KEYWORDS: readonly (readonly [CategoryIcon, readonly string[]])[] = [
  ["calculator", ["حاسب"]],
  ["palette", ["الوان", "تلوين", "رسم", "فلوماستر", "جواش"]],
  ["highlighter", ["هايلايت", "فسفوري"]],
  ["eraser", ["استيك", "ممحا", "برايه", "براي"]],
  ["ruler", ["مسطر", "مساطر", "هندس"]],
  ["scissors", ["مقص"]],
  ["notebook-pen", ["كراس", "كشكول", "كشاكيل", "دفتر", "دفاتر", "نوت"]],
  ["pen", ["جاف", "حبر"]],
  ["pencil", ["قلم", "اقلام", "رصاص", "ادوات", "قرطاسي"]],
  ["backpack", ["شنط", "حقيب", "حقائب"]],
  ["folder", ["حافظ", "ملف", "دوسيه", "فايل"]],
  ["paperclip", ["مكتبي", "دباس", "مشبك", "لاصق"]],
  ["file-text", ["ورق", "كوشيه"]],
  ["printer", ["طباع", "تصوير"]],
  ["book-open", ["كتاب", "كتب", "خارجي", "قصص", "قصه", "روايات"]],
  ["flask-conical", ["علوم", "كيمياء", "فيزياء"]],
  ["sigma", ["رياضيات", "حساب"]],
  ["languages", ["انجليزي", "فرنساوي", "فرنسي", "عربي", "لغه", "لغات"]],
  ["globe", ["دراسات", "جغرافيا", "تاريخ"]],
  ["puzzle", ["بازل", "بزل", "تركيب"]],
  ["shapes", ["تعليمي", "مكعبات", "صلصال"]],
  ["gamepad-2", ["لعب", "العاب", "لعبه"]],
  ["baby", ["اطفال", "طفل", "بيبي", "حضانه", "kg"]],
  ["gift", ["هدايا", "هديه", "تغليف"]],
  ["shirt", ["ملابس", "يونيفورم"]],
  ["laptop", ["الكترون", "كمبيوتر", "لابتوب", "سماع"]],
  ["graduation-cap", ["مرحله", "ابتدائ", "اعداد", "ثانو", "صف", "مدرس", "جامع"]],
];

/** Folds the spellings people mix: diacritics and tatweel dropped, alef forms → ا, ة → ه, ى → ي, Latin lower-cased. */
export function normalize(text: string): string {
  return text
    .replace(/[ً-ْـ]/g, "")
    .replace(/[أإآٱ]/g, "ا")
    .replace(/ة/g, "ه")
    .replace(/ى/g, "ي")
    .toLowerCase();
}

/** The icon a name suggests, or null when no keyword matches. */
export function iconFromName(name: string): CategoryIcon | null {
  const text = normalize(name);

  return KEYWORDS.find(([, words]) => words.some((word) => text.includes(word)))?.[0] ?? null;
}

type IconSource = {
  name: string;
  kind: CategoryKind;
  icon: CategoryIcon | null;
  children?: readonly { name: string }[];
};

export function iconFor(category: IconSource): CategoryIcon {
  // Truthy rather than !== null: an older cached response has no `icon` key at all.
  if (category.icon) {
    return category.icon;
  }

  for (const name of [category.name, ...(category.children ?? []).map((child) => child.name)]) {
    const guess = iconFromName(name);

    if (guess !== null) {
      return guess;
    }
  }

  return BY_KIND[category.kind];
}
