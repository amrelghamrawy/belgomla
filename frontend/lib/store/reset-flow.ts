// The verified password-reset code, carried from /account/verify to /account/reset (BR-CU04). Kept in memory
// only (not in the URL, not in storage): a reload loses it and the customer starts again from /account/forgot.

export type ResetFlow = { phone: string; code: string };

let current: ResetFlow | null = null;

export function rememberReset(flow: ResetFlow): void {
  current = flow;
}

export function pendingReset(): ResetFlow | null {
  return current;
}

export function forgetReset(): void {
  current = null;
}
