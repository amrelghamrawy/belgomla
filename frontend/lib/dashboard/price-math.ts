// Display-only price maths for the dashboard forms (BR-PR10). The server computes and stores the real price;
// these show the merchant what a percentage means before saving. Integer piasters and basis points, never
// floats: 10.10 at 25% is 7.575, which half-up rounding makes 7.58 (D27) and a float would make 7.57.

const MONEY = /^\d{1,10}(\.\d{1,2})?$/;
const PERCENT = /^\d{1,2}(\.\d{1,2})?$/;

/** "120.5" → 12050n piasters; null when it is not a money amount. */
export function toPiasters(amount: string): bigint | null {
  const value = amount.trim();

  if (!MONEY.test(value)) {
    return null;
  }

  const [whole = "0", fraction = ""] = value.split(".");

  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));
}

export function fromPiasters(piasters: bigint): string {
  const whole = piasters / 100n;
  const fraction = piasters % 100n;

  return `${whole}.${fraction.toString().padStart(2, "0")}`;
}

/** "12.5" → 1250n basis points; null unless 0 < percent < 100 (the backend's gt:0, lt:100). */
export function toBasisPoints(percent: string): bigint | null {
  const value = percent.trim();

  if (!PERCENT.test(value)) {
    return null;
  }

  const [whole = "0", fraction = ""] = value.split(".");
  const points = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));

  return points > 0n && points < 10000n ? points : null;
}

/** a / b rounded half-up, for non-negative integers. */
function divideHalfUp(a: bigint, b: bigint): bigint {
  const quotient = a / b;

  return (a % b) * 2n >= b ? quotient + 1n : quotient;
}

/** D27: cover × (100 − percent) / 100, half-up to the piaster. null when an input is not valid. */
export function priceFromPercent(cover: string, percent: string): string | null {
  const coverPiasters = toPiasters(cover);
  const points = toBasisPoints(percent);

  if (coverPiasters === null || coverPiasters === 0n || points === null) {
    return null;
  }

  return fromPiasters(divideHalfUp(coverPiasters * (10000n - points), 10000n));
}

/** The discount a price implies off the cover price ("90.00" of "120.00" → "25.00"); null if not a discount. */
export function percentFromPrice(cover: string, price: string): string | null {
  const coverPiasters = toPiasters(cover);
  const pricePiasters = toPiasters(price);

  if (coverPiasters === null || pricePiasters === null || coverPiasters === 0n || pricePiasters >= coverPiasters) {
    return null;
  }

  return fromPiasters(divideHalfUp((coverPiasters - pricePiasters) * 10000n, coverPiasters));
}

/** One row of the tiers editor: a threshold and either a unit price or a percentage off the cover price. */
export type TierDraft = { min_quantity: string; mode: "price" | "percent"; value: string };

/** The unit price a tier row stands for, when it can be known on the client. */
export function tierPrice(tier: TierDraft, cover: string | null): string | null {
  if (tier.mode === "percent") {
    return cover === null ? null : priceFromPercent(cover, tier.value);
  }

  const piasters = toPiasters(tier.value);

  return piasters === null ? null : fromPiasters(piasters);
}

export type TierMessages = {
  duplicate: (min: number) => string;
  invalidQuantity: string;
  invalidValue: string;
  notPositive: (min: number) => string;
  aboveBase: (min: number) => string;
  percentNeedsCover: string;
};

/**
 * The problems of each tier row, by index, mirroring the backend's checks (`invalid_tiers`): a threshold is a
 * whole number ≥ 1 and appears once, a price is above zero and not above the base price, and a percentage
 * needs a cover price (`cover_price_required`).
 */
export function tierProblems(
  tiers: readonly TierDraft[],
  context: { cover: string | null; basePrice: string | null },
  messages: TierMessages,
): Record<number, string> {
  const problems: Record<number, string> = {};
  const seen = new Set<number>();
  const base = context.basePrice === null ? null : toPiasters(context.basePrice);

  tiers.forEach((tier, index) => {
    const min = Number(tier.min_quantity);

    if (!/^\d+$/.test(tier.min_quantity.trim()) || min < 1) {
      problems[index] = messages.invalidQuantity;
      return;
    }

    if (seen.has(min)) {
      problems[index] = messages.duplicate(min);
      return;
    }

    seen.add(min);

    if (tier.mode === "percent" && context.cover === null) {
      problems[index] = messages.percentNeedsCover;
      return;
    }

    const valid = tier.mode === "percent" ? toBasisPoints(tier.value) !== null : toPiasters(tier.value) !== null;

    if (!valid) {
      problems[index] = messages.invalidValue;
      return;
    }

    const price = tierPrice(tier, context.cover);
    const piasters = price === null ? null : toPiasters(price);

    if (piasters !== null && piasters <= 0n) {
      problems[index] = messages.notPositive(min);
    } else if (piasters !== null && base !== null && piasters > base) {
      problems[index] = messages.aboveBase(min);
    }
  });

  return problems;
}

/** The API body for the tiers: `unit_price` or `discount_percent`, never both (BR-PR10). */
export function tiersBody(tiers: readonly TierDraft[]) {
  return tiers.map((tier) =>
    tier.mode === "percent"
      ? { min_quantity: Number(tier.min_quantity), discount_percent: tier.value.trim() }
      : { min_quantity: Number(tier.min_quantity), unit_price: tier.value.trim() },
  );
}
