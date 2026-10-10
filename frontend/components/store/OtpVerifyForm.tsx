"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { isApiError } from "@/lib/api/errors";
import type { OtpPurpose } from "@/lib/api/types";
import { safeNext } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { useResendOtp, useVerifyOtp } from "@/lib/store/auth";
import { useCountdown } from "@/lib/store/countdown";
import { rememberReset } from "@/lib/store/reset-flow";

import { AuthCard } from "./AuthCard";

/** BR-CU07: a new code (or the SMS fallback) can be asked for 60 s after the last one. */
export const RESEND_SECONDS = 60;

const CODE_LENGTH = 6;

function isPurpose(value: string | null): value is OtpPurpose {
  return value === "register" || value === "password_reset";
}

/**
 * The 6-digit code step (BR-CU07) of registration and password reset. Resending is locked for 60 s after each
 * code; then the customer can resend over WhatsApp or ask for an SMS instead. A 429 restarts the wait with the
 * server's `retry_after`.
 */
export function OtpVerifyForm() {
  const router = useRouter();
  const params = useSearchParams();
  const phone = params.get("phone") ?? "";
  const purpose = params.get("purpose");
  const next = safeNext(params.get("next"), "/");
  const [via, setVia] = useState(params.get("via") ?? "whatsapp");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pendingApproval, setPendingApproval] = useState(false);
  const countdown = useCountdown(RESEND_SECONDS);
  const verify = useVerifyOtp();
  const resend = useResendOtp();

  if (phone === "" || !isPurpose(purpose)) {
    return (
      <AuthCard title={ar.otp.title}>
        <p className="text-muted-foreground">{ar.otp.missingPhone}</p>
      </AuthCard>
    );
  }

  if (pendingApproval) {
    return (
      <AuthCard title={ar.auth.verifyFirst}>
        <Alert>
          <AlertDescription>{ar.auth.pendingApproval}</AlertDescription>
        </Alert>
      </AuthCard>
    );
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (code.length !== CODE_LENGTH) {
      setError(ar.validation.otp);
      return;
    }

    setError(null);
    verify.mutate(
      { phone, code, purpose },
      {
        onSuccess: (customer) => {
          if (purpose === "password_reset") {
            rememberReset({ phone, code });
            router.push("/account/reset");
          } else if (customer?.status === "pending_approval") {
            setPendingApproval(true);
          } else {
            router.replace(next);
          }
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  const send = (channel: "whatsapp" | "sms") =>
    resend.mutate(
      { phone, purpose, channel },
      {
        onSuccess: (sent) => {
          setVia(sent.otp_sent_via);
          setCode("");
          setError(null);
          countdown.restart(RESEND_SECONDS);
          toast.success(ar.otp.resent);
        },
        onError: (failure) => {
          const retryAfter = isApiError(failure, "rate_limited") ? Number(failure.details?.retry_after) : NaN;

          if (Number.isFinite(retryAfter) && retryAfter > 0) {
            countdown.restart(retryAfter);
          }

          setError(messageFor(failure));
        },
      },
    );

  const waiting = countdown.remaining > 0;

  return (
    <AuthCard
      title={ar.otp.title}
      description={ar.otp.sentVia(ar.otp.channels[via] ?? via, phone)}
      footer={
        <>
          <Button
            type="button"
            variant="link"
            className="h-11"
            disabled={waiting || resend.isPending}
            onClick={() => send("whatsapp")}
          >
            {waiting ? ar.otp.resendIn(countdown.remaining) : ar.otp.resend}
          </Button>
          <Button
            type="button"
            variant="link"
            className="h-auto min-h-11 whitespace-normal"
            disabled={waiting || resend.isPending}
            onClick={() => send("sms")}
          >
            {ar.otp.sendSms}
          </Button>
          {purpose === "register" && (
            <Link href="/account/register" className="text-muted-foreground underline-offset-4 hover:underline">
              {ar.auth.registerTitle}
            </Link>
          )}
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <FieldGroup>
          <FieldLabel htmlFor="otp">{ar.otp.code}</FieldLabel>
          <div dir="ltr" className="flex justify-center">
            <InputOTP
              id="otp"
              maxLength={CODE_LENGTH}
              value={code}
              onChange={setCode}
              inputMode="numeric"
              pattern="^[0-9]*$"
              autoComplete="one-time-code"
              autoFocus
              aria-invalid={error !== null}
            >
              <InputOTPGroup>
                {Array.from({ length: CODE_LENGTH }, (_, index) => (
                  <InputOTPSlot key={index} index={index} className="size-11 text-lg" />
                ))}
              </InputOTPGroup>
            </InputOTP>
          </div>
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <Button type="submit" size="lg" className="h-11 w-full" disabled={verify.isPending}>
            {verify.isPending ? ar.otp.verifying : ar.otp.verify}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
