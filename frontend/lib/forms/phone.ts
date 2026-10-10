import { z } from "zod";

import { ar } from "@/lib/copy/ar";

/** Any Egyptian mobile the backend accepts: 01xxxxxxxxx, +201xxxxxxxxx, 201xxxxxxxxx; spaces and dashes ignored. */
const EGYPTIAN_MOBILE = /^(\+?20|0)?1[0125]\d{8}$/;

/**
 * The phone field of every storefront form. Mirrors the backend's EgyptianPhone rule, which stays the authority;
 * this only catches typos before the round trip.
 */
export function phoneField() {
  return z
    .string()
    .trim()
    .min(1, ar.validation.required)
    .refine((value) => EGYPTIAN_MOBILE.test(value.replace(/[\s-]/g, "")), ar.validation.phone);
}
