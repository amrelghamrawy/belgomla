"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { isApiError } from "@/lib/api/errors";
import { safeNext, useCustomerLogin } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { phoneField } from "@/lib/forms/phone";
import { useResendOtp } from "@/lib/store/auth";
import { verifyHref } from "@/lib/store/verify";

import { AuthCard } from "./AuthCard";

// Mirrors CustomerLoginRequest. The backend's EgyptianPhone rule is the authority; this only catches typos.
export const customerLoginSchema = z.object({
  phone: phoneField(),
  password: z.string().min(1, ar.validation.required),
});

type CustomerLoginValues = z.infer<typeof customerLoginSchema>;

/**
 * Customer login (BR-CU04). A customer who registered but never entered the code (`phone_not_verified`) is sent
 * a new one and taken to the verify step.
 */
export function CustomerLoginForm() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"), "/");
  const login = useCustomerLogin();
  const resend = useResendOtp();
  const form = useForm<CustomerLoginValues>({
    resolver: zodResolver(customerLoginSchema),
    defaultValues: { phone: "", password: "" },
  });

  const verifyPhone = (phone: string) =>
    resend.mutate(
      { phone, purpose: "register" },
      {
        onSuccess: (sent) => router.push(verifyHref({ phone, purpose: "register", via: sent.otp_sent_via, next })),
        onError: (error) => form.setError("root", { message: messageFor(error) }),
      },
    );

  const submit = form.handleSubmit((values) =>
    login.mutate(values, {
      onSuccess: () => router.replace(next),
      onError: (error) => {
        if (isApiError(error, "phone_not_verified")) {
          verifyPhone(values.phone);
        } else if (!applyServerErrors(error, ["phone", "password"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;
  const busy = login.isPending || resend.isPending;

  return (
    <AuthCard
      title={ar.auth.customerLoginTitle}
      footer={
        <>
          <Link href="/account/forgot" className="text-primary underline-offset-4 hover:underline">
            {ar.auth.forgot}
          </Link>
          <p>
            {ar.auth.noAccount}{" "}
            <Link
              href={`/account/register${next === "/" ? "" : `?next=${encodeURIComponent(next)}`}`}
              className="text-primary underline-offset-4 hover:underline"
            >
              {ar.auth.register}
            </Link>
          </p>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <FieldGroup>
          <TextField
            id="phone"
            label={ar.auth.phone}
            type="tel"
            dir="ltr"
            inputMode="tel"
            autoComplete="tel"
            registration={form.register("phone")}
            error={errors.phone}
          />
          <TextField
            id="password"
            label={ar.auth.password}
            type="password"
            dir="ltr"
            autoComplete="current-password"
            registration={form.register("password")}
            error={errors.password}
          />
          {errors.root?.message !== undefined && <FieldError>{errors.root.message}</FieldError>}
          <Button type="submit" size="lg" className="h-11 w-full" disabled={busy}>
            {busy ? ar.auth.submitting : ar.auth.submitLogin}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
