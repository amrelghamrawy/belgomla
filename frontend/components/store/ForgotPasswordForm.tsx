"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { phoneField } from "@/lib/forms/phone";
import { useForgotPassword } from "@/lib/store/auth";
import { verifyHref } from "@/lib/store/verify";

import { AuthCard } from "./AuthCard";

export const forgotPasswordSchema = z.object({ phone: phoneField() });

type ForgotValues = z.infer<typeof forgotPasswordSchema>;

/** Step 1 of a password reset (BR-CU04): send a code. The answer is the same for unknown phones (D52). */
export function ForgotPasswordForm() {
  const router = useRouter();
  const forgot = useForgotPassword();
  const form = useForm<ForgotValues>({ resolver: zodResolver(forgotPasswordSchema), defaultValues: { phone: "" } });

  const submit = form.handleSubmit((values) =>
    forgot.mutate(values, {
      onSuccess: (sent) =>
        router.push(verifyHref({ phone: values.phone, purpose: "password_reset", via: sent.otp_sent_via })),
      onError: (error) => {
        if (!applyServerErrors(error, ["phone"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;

  return (
    <AuthCard
      title={ar.auth.forgotTitle}
      description={ar.auth.forgotBody}
      footer={
        <Link href="/account/login" className="text-primary underline-offset-4 hover:underline">
          {ar.auth.submitLogin}
        </Link>
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
          {errors.root?.message !== undefined && <FieldError role="alert">{errors.root.message}</FieldError>}
          <Button type="submit" size="lg" className="h-11 w-full" disabled={forgot.isPending}>
            {forgot.isPending ? ar.auth.sending : ar.auth.sendCode}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
