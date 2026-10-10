"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { loginPaths } from "@/lib/api/client";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { useResetPassword } from "@/lib/store/auth";
import { forgetReset, pendingReset } from "@/lib/store/reset-flow";

import { AuthCard } from "./AuthCard";

export const resetPasswordSchema = z.object({
  password: z.string().min(8, ar.validation.passwordMin).max(200, ar.validation.max(200)),
});

type ResetValues = z.infer<typeof resetPasswordSchema>;

/** Step 3 of a password reset: the new password, with the code verified on the previous page. Then log in. */
export function ResetPasswordForm() {
  const router = useRouter();
  const [flow] = useState(pendingReset);
  const reset = useResetPassword();
  const form = useForm<ResetValues>({ resolver: zodResolver(resetPasswordSchema), defaultValues: { password: "" } });

  if (flow === null) {
    return (
      <AuthCard title={ar.auth.resetTitle}>
        <p className="text-muted-foreground">
          {ar.otp.missingPhone}{" "}
          <Link href="/account/forgot" className="text-primary underline">
            {ar.auth.forgotTitle}
          </Link>
        </p>
      </AuthCard>
    );
  }

  const submit = form.handleSubmit((values) =>
    reset.mutate(
      { ...flow, password: values.password },
      {
        onSuccess: () => {
          forgetReset();
          toast.success(ar.auth.resetDone);
          router.replace(loginPaths.store);
        },
        onError: (error) => {
          if (!applyServerErrors(error, ["password"], form.setError)) {
            form.setError("root", { message: messageFor(error) });
          }
        },
      },
    ),
  );

  const { errors } = form.formState;

  return (
    <AuthCard title={ar.auth.resetTitle}>
      <form onSubmit={submit} noValidate>
        <FieldGroup>
          <TextField
            id="password"
            label={ar.auth.newPassword}
            type="password"
            dir="ltr"
            autoComplete="new-password"
            placeholder={ar.auth.passwordHint}
            registration={form.register("password")}
            error={errors.password}
          />
          {errors.root?.message !== undefined && <FieldError role="alert">{errors.root.message}</FieldError>}
          <Button type="submit" size="lg" className="h-11 w-full" disabled={reset.isPending}>
            {reset.isPending ? ar.saving : ar.auth.resetSubmit}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
