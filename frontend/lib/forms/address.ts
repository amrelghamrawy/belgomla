import { z } from "zod";

import { ar } from "@/lib/copy/ar";
import { phoneField } from "@/lib/forms/phone";

const optional = (max: number) => z.string().trim().max(max, ar.validation.max(max));
const required = (max: number) => optional(max).min(1, ar.validation.required);

// Mirrors SaveAddressRequest (BR-CU03).
export const addressSchema = z.object({
  label: required(50),
  governorate: required(50),
  city: required(80),
  address_line: required(300),
  landmark: optional(200),
  phone: z.union([z.literal(""), phoneField()]),
  is_default: z.boolean(),
});

export type AddressFormValues = z.infer<typeof addressSchema>;
