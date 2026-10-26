// BR-PR15 (V2 Task 12): how long a promotion has left, for the storefront's «ينتهي خلال …». Pure, so the badge and
// its tests agree; the clock comes from useMinuteClock (null while server-rendering).

export type TimeLeft = { unit: "days" | "hours" | "minutes"; value: number };

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * What is left until `endsAt`: whole days from two days on, whole hours from an hour on, else minutes (rounded up,
 * so the last seconds still read one minute). Null once it ended.
 */
export function timeLeft(endsAt: string, now: Date): TimeLeft | null {
  const left = new Date(endsAt).getTime() - now.getTime();

  if (Number.isNaN(left) || left <= 0) {
    return null;
  }

  if (left >= 2 * DAY) {
    return { unit: "days", value: Math.floor(left / DAY) };
  }

  if (left >= HOUR) {
    return { unit: "hours", value: Math.floor(left / HOUR) };
  }

  return { unit: "minutes", value: Math.ceil(left / MINUTE) };
}
