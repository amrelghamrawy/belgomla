import { useRef } from "react";

/** 128 random bits as hex. Not crypto.randomUUID(): it exists only on https, and dev/LAN stores run on http. */
export function newIdempotencyKey(prefix: string): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));

  return `${prefix}-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("")}`;
}

/**
 * The `Idempotency-Key` for a retry-safe write (D55). Retrying the same body (double tap, a timeout) reuses
 * the key, so the backend replays the first result instead of acting twice; a changed body gets a new key,
 * and `reset()` after a success makes the next submission a new one.
 */
export function useIdempotencyKey(prefix: string) {
  const attempt = useRef<{ key: string; body: string } | null>(null);

  return {
    keyFor(body: unknown): string {
      const serialized = JSON.stringify(body);

      if (attempt.current === null || attempt.current.body !== serialized) {
        attempt.current = { key: newIdempotencyKey(prefix), body: serialized };
      }

      return attempt.current.key;
    },
    reset(): void {
      attempt.current = null;
    },
  };
}
