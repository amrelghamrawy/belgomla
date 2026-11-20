import type { Money, UnitCost } from "@/lib/api/types";
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

const UNIT_COST = /^(\d+)\.(\d{2})(\d{2})$/;

/**
 * A cost per base unit (D96, "5.5000", 4 decimals) as money: the last two decimals are dropped when they are zero,
 * kept otherwise ("5.5000" → "5.50 ج.م", "4.1667" → "4.1667 ج.م"), so a carton price ÷ its factor is never rounded
 * on screen. Never a float.
 */
export function formatUnitCost(cost: UnitCost): string {
  const match = UNIT_COST.exec(cost);

  if (match === null) {
    throw new RangeError(`Not a unit cost string: "${cost}"`);
  }

  const [, whole = "0", cents = "00", rest = "00"] = match;
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");

  return `${grouped}.${cents}${rest === "00" ? "" : rest} ${ar.currency}`;
}
