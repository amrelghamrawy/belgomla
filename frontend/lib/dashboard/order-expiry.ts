"use client";

import { useEffect, useState } from "react";

// D81: a pending order cancels itself at `expires_at` when the store turned the automatic expiry on.

/** Whole hours and minutes left until `expiresAt` (rounded up to the minute), zero once it has passed. */
export function expiryLeft(expiresAt: string, now: number): { hours: number; minutes: number } {
  const minutes = Math.max(0, Math.ceil((Date.parse(expiresAt) - now) / 60_000));

  return { hours: Math.floor(minutes / 60), minutes: minutes % 60 };
}

/** The current time, refreshed every minute: enough for a countdown shown in hours and minutes. */
export function useMinuteClock(): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60_000);

    return () => clearInterval(timer);
  }, []);

  return now;
}
