import type { Money } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

const MONEY = /^(-?)(\d+)\.(\d{2})$/;

/**
 * "1250.50" → "1,250.50 ج.م". Money arrives as a two-decimal string (08 §0) and is never turned into a float
 * here: the grouping works on the digits. Western digits (D39).
 */
export function formatMoney(amount: Money): string {
  const match = MONEY.exec(amount);

  if (match === null) {
    throw new RangeError(`Not a money string: "${amount}"`);
  }

  const [, sign, whole = "0", fraction] = match;
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");

  return `${sign}${grouped}.${fraction} ${ar.currency}`;
}

/** A percent string from the API ("20.00", "12.50") without trailing zeros: "20", "12.5". */
export function formatPercent(percent: string): string {
  return percent.includes(".") ? percent.replace(/\.?0+$/, "") : percent;
}
