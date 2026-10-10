"use client";

import { useCallback, useEffect, useState } from "react";

/**
 * Seconds left until `restart(seconds)` runs out, ticking once a second (the OTP resend wait, BR-CU07).
 * Based on the clock rather than on counting ticks, so a throttled background tab stays right.
 */
export function useCountdown(initialSeconds: number) {
  const [endsAt, setEndsAt] = useState(() => Date.now() + initialSeconds * 1000);
  const [now, setNow] = useState(() => Date.now());
  const remaining = Math.max(0, Math.ceil((endsAt - now) / 1000));

  useEffect(() => {
    if (remaining === 0) {
      return;
    }

    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [remaining]);

  const restart = useCallback((seconds: number) => {
    const start = Date.now();
    setNow(start);
    setEndsAt(start + seconds * 1000);
  }, []);

  return { remaining, restart };
}
