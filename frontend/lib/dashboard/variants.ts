// Product variants (V2 Task 14, BR-P14, D92): a product has at most two options (colour, size) and every combination
// of their values is its own SKU. Pure helpers shared by the product form; the backend's VariantGenerator follows the
// same code rule, so a code generated here is the one the server would make.

/** At most two options per product (D92). */
export const MAX_OPTIONS = 2;
/** Up to 20 values per option (D92). */
export const MAX_OPTION_VALUES = 20;
/** Option names and values are at most 50 characters. */
export const MAX_OPTION_TEXT = 50;

const SEPARATORS = "-._/";
const SEPARATOR = /^[-._/]$/;

export type OptionDraft = { name: string; values: readonly string[] };

export type VariantDraft = {
  /** One value of every option, in option order. */
  option_values: string[];
  code: string;
};

/**
 * A value's part of a SKU code: its ASCII letters and digits uppercased, any other run collapsed to `-` and trimmed;
 * a value with nothing ASCII left (an Arabic one) is its 1-based position in the option instead.
 */
export function valueSlug(value: string, position: number): string {
  const slug = value
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

  return slug === "" ? String(position) : slug;
}

/** Every combination of the options' values, the first option varying slowest (option and value order kept). */
export function combinations(options: readonly OptionDraft[]): string[][] {
  if (options.length === 0) {
    return [];
  }

  return options.reduce<string[][]>(
    (rows, option) => rows.flatMap((row) => option.values.map((value) => [...row, value])),
    [[]],
  );
}

/** The suffix a combination adds to the base code: `-{slug1}-{slug2}`. */
function codeSuffix(options: readonly OptionDraft[], values: readonly string[]): string {
  return values
    .map((value, index) => `-${valueSlug(value, (options[index]?.values.indexOf(value) ?? 0) + 1)}`)
    .join("");
}

/**
 * The SKU drafts of a product with options: one per combination, coded `{base}-{slug1}-{slug2}`. For example base
 * `PEN-01`, colours أزرق/أسود and sizes S/M give `PEN-01-1-S`, `PEN-01-1-M`, `PEN-01-2-S`, `PEN-01-2-M`.
 */
export function generateVariants(baseCode: string, options: readonly OptionDraft[]): VariantDraft[] {
  const base = baseCode.trim();

  return combinations(options).map((values) => ({
    option_values: values,
    code: `${base}${codeSuffix(options, values)}`,
  }));
}

/** The label a shopper sees («أزرق / M»). */
export function variantLabel(values: readonly string[]): string {
  return values.join(" / ");
}

/** A key identifying a combination, for matching rows across regenerations. */
export function combinationKey(values: readonly string[]): string {
  return JSON.stringify(values.map((value) => value.trim()));
}

/**
 * The base code the product's variant codes were generated from: the first SKU's code without its generated suffix,
 * else the longest common prefix of the codes (trailing separators dropped), else the first code.
 */
export function baseCodeOf(
  options: readonly OptionDraft[],
  skus: readonly { code: string; option_values: readonly string[] }[],
): string {
  const first = skus[0];

  if (first === undefined) {
    return "";
  }

  const suffix = codeSuffix(options, first.option_values);

  if (suffix !== "" && first.code.length > suffix.length && first.code.toUpperCase().endsWith(suffix)) {
    return first.code.slice(0, first.code.length - suffix.length);
  }

  let prefix = first.code;

  for (const sku of skus) {
    while (!sku.code.startsWith(prefix)) {
      prefix = prefix.slice(0, -1);
    }
  }

  // The prefix ends at a separator in every code ("X-" of "X-BLUE" and "X-BLACK", not "X-BL").
  const atBoundary = skus.every(
    (sku) => sku.code.length === prefix.length || SEPARATOR.test(sku.code[prefix.length] ?? ""),
  );

  if (!atBoundary) {
    const cut = Math.max(...[...SEPARATORS].map((separator) => prefix.lastIndexOf(separator)));
    prefix = cut < 0 ? "" : prefix.slice(0, cut);
  }

  const trimmed = prefix.replace(/[-._/]+$/, "");

  return trimmed === "" ? first.code : trimmed;
}
