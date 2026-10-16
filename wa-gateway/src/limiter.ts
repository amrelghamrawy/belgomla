/**
 * Sliding-window send limit per session (D46/D70: ≤ 10 messages a minute per number). It never waits: a send
 * over the limit is refused with the time until a slot frees, and Laravel's job retries after that.
 */
export class Limiter {
  private stamps: number[] = [];

  constructor(
    private readonly perWindow: number,
    private readonly windowMs: number,
    private readonly now: () => number = Date.now,
  ) {}

  /** 0 when the send may go now (and counts it), otherwise the milliseconds to wait. */
  take(): number {
    const now = this.now();
    this.stamps = this.stamps.filter((stamp) => now - stamp < this.windowMs);

    if (this.stamps.length < this.perWindow) {
      this.stamps.push(now);

      return 0;
    }

    return this.windowMs - (now - (this.stamps[0] ?? now));
  }
}
