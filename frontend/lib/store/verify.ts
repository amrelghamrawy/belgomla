import type { OtpPurpose } from "@/lib/api/types";

// Where to enter a code that was just sent (BR-CU07). Kept apart from the form so the pages that link to it
// (register, login, forgot) do not load the OTP input.

type VerifyTarget = { phone: string; purpose: OtpPurpose; via: string; next?: string };

/** The verify page URL for a phone that was just sent a code. */
export function verifyHref({ phone, purpose, via, next }: VerifyTarget): string {
  const params = new URLSearchParams({ phone, purpose, via });

  if (next !== undefined && next !== "/") {
    params.set("next", next);
  }

  return `/account/verify?${params.toString()}`;
}
