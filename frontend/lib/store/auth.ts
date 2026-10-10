"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type {
  Customer,
  Invitation,
  OtpSent,
  RegisterRequest,
  ResendOtpRequest,
  ResetPasswordRequest,
  VerifyOtpRequest,
} from "@/lib/api/types";

// Customer self-service auth (08 §2, BR-CU04, BR-CU07, BR-CU08). Login and logout live in lib/auth/session.ts.
// Every call here handles 401 itself: these screens are for guests.
const guest = { redirectOn401: false } as const;

export function useRegister() {
  return useMutation({
    mutationFn: (body: RegisterRequest) =>
      apiFetch<OtpSent>("/api/store/auth/register", { method: "POST", body, ...guest }),
  });
}

/** For `register` the API answers with the now logged-in customer; for `password_reset` with 204 (undefined). */
export function useVerifyOtp() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: VerifyOtpRequest) =>
      apiFetch<Customer | undefined>("/api/store/auth/verify-otp", { method: "POST", body, ...guest }),
    onSuccess: (customer) => {
      if (customer !== undefined) {
        queryClient.setQueryData(queryKeys.customerSession, customer);
      }
    },
  });
}

/** A new code; `channel: "sms"` is the "send by SMS instead" link (BR-CU07). */
export function useResendOtp() {
  return useMutation({
    mutationFn: (body: ResendOtpRequest) =>
      apiFetch<OtpSent>("/api/store/auth/resend-otp", { method: "POST", body, ...guest }),
  });
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (body: { phone: string }) =>
      apiFetch<OtpSent>("/api/store/auth/forgot-password", { method: "POST", body, ...guest }),
  });
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (body: ResetPasswordRequest) =>
      apiFetch<void>("/api/store/auth/reset-password", { method: "POST", body, ...guest }),
  });
}

export function useInvitation(token: string) {
  return useQuery({
    queryKey: queryKeys.invitation(token),
    queryFn: () => apiFetch<Invitation>(`/api/store/auth/invitation/${encodeURIComponent(token)}`, guest),
    retry: false,
  });
}

export function useAcceptInvitation(token: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: { password: string }) =>
      apiFetch<Customer>(`/api/store/auth/invitation/${encodeURIComponent(token)}`, { method: "POST", body, ...guest }),
    onSuccess: (customer) => queryClient.setQueryData(queryKeys.customerSession, customer),
  });
}
