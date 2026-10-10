import type { StoreProduct } from "@/lib/api/types";

// A generated cover for a product without a photo (D67), laid out like an Egyptian outside-textbook cover: the
// subject large, the grade under it, the publisher as a label. Stores that do not sell books fall back to the
// category and the product name. Everything is derived from the product, so a product always gets the same cover.

/** Printed-cover inks, indexed by `CoverSpec.tone`. Content colours, not brand colours: the same in every store. */
export const COVER_INKS = [
  "oklch(0.5 0.12 150)",
  "oklch(0.42 0.06 240)",
  "oklch(0.6 0.16 50)",
  "oklch(0.5 0.1 178)",
  "oklch(0.44 0.1 318)",
  "oklch(0.47 0.16 24)",
  "oklch(0.44 0.13 258)",
  "oklch(0.55 0.15 5)",
] as const;

export const COVER_TONES = COVER_INKS.length;
export const COVER_LAYOUTS = 3;

export type CoverSpec = {
  title: string;
  subtitle: string | null;
  badge: string | null;
  note: string | null;
  /** Index into the cover palette, from the subject (or category) so one subject keeps one colour. */
  tone: number;
  /** Decoration style, from the publisher (or category) so one publisher's books look alike. */
  layout: number;
  /** A long title (a product name) is set smaller. */
  compact: boolean;
};

/**
 * A stable non-negative hash of a string (31-multiplier, wrapped to 32 bits). The start value 3 gives the usual
 * subjects (عربي، إنجليزي، رياضيات، علوم، دراسات) five different tones and the three big publishers three layouts.
 */
export function hashText(text: string): number {
  let hash = 3;

  for (const char of text) {
    hash = (hash * 31 + (char.codePointAt(0) ?? 0)) >>> 0;
  }

  return hash;
}

function field(product: StoreProduct, key: string): string | null {
  return product.custom_fields.find((value) => value.key === key)?.value ?? null;
}

export function coverFor(product: StoreProduct): CoverSpec {
  const subject = field(product, "subject");
  const grade = field(product, "grade");
  const publisher = field(product, "publisher");
  const category = product.category?.name ?? null;
  const title = subject ?? category ?? product.name;
  const subtitle = subject !== null ? (grade ?? category) : category !== null ? product.name : null;

  return {
    title,
    subtitle,
    badge: publisher,
    note: field(product, "term"),
    tone: hashText(subject ?? category ?? product.name) % COVER_TONES,
    layout: hashText(publisher ?? String(product.category?.id ?? product.id)) % COVER_LAYOUTS,
    compact: title.length > 12,
  };
}

/**
 * The letter on a store's mark when it has no logo: the first letter of the last word of its name, without the
 * article "ال" ("مكتبة النور" → "ن").
 */
export function monogram(name: string): string {
  const word = name.trim().split(/\s+/).at(-1) ?? "";
  const bare = word.startsWith("ال") && word.length > 2 ? word.slice(2) : word;

  return [...bare][0] ?? "";
}
