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
import { safeNext } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { phoneField } from "@/lib/forms/phone";
import { useRegister } from "@/lib/store/auth";
import { verifyHref } from "@/lib/store/verify";

import { AuthCard } from "./AuthCard";

// Mirrors RegisterCustomerRequest: name ≤ 150, an Egyptian phone, password 8–200 (D50).
export const registerSchema = z.object({
  name: z.string().trim().min(1, ar.validation.required).max(150, ar.validation.max(150)),
  phone: phoneField(),
  password: z.string().min(8, ar.validation.passwordMin).max(200, ar.validation.max(200)),
});

type RegisterValues = z.infer<typeof registerSchema>;

/** Self-registration (BR-CU04): creates a pending account and sends an OTP; the next step is /account/verify. */
export function RegisterForm() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"), "/");
  const register = useRegister();
  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: "", phone: "", password: "" },
  });

  const submit = form.handleSubmit((values) =>
    register.mutate(values, {
      onSuccess: (sent) =>
        router.push(verifyHref({ phone: values.phone, purpose: "register", via: sent.otp_sent_via, next })),
      onError: (error) => {
        if (isApiError(error, "phone_taken")) {
          form.setError("phone", { message: messageFor(error) });
        } else if (!applyServerErrors(error, ["name", "phone", "password"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;

  return (
    <AuthCard
      title={ar.auth.registerTitle}
      footer={
        <p>
          {ar.auth.haveAccount}{" "}
          <Link
            href={`/account/login?next=${encodeURIComponent(next)}`}
            className="text-primary underline-offset-4 hover:underline"
          >
            {ar.auth.submitLogin}
          </Link>
        </p>
      }
    >
      <form onSubmit={submit} noValidate>
        <FieldGroup>
          <TextField
            id="name"
            label={ar.auth.name}
            autoComplete="organization"
            registration={form.register("name")}
            error={errors.name}
          />
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
            autoComplete="new-password"
            placeholder={ar.auth.passwordHint}
            registration={form.register("password")}
            error={errors.password}
          />
          {errors.root?.message !== undefined && <FieldError role="alert">{errors.root.message}</FieldError>}
          <Button type="submit" size="lg" className="h-11 w-full" disabled={register.isPending}>
            {register.isPending ? ar.auth.registering : ar.auth.register}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
