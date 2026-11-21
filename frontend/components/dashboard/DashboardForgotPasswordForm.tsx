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
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { loginPaths } from "@/lib/api/client";
import { useDashboardForgotPassword, useDashboardResetPassword } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";

// Mirror DashboardForgotPasswordRequest and DashboardResetPasswordRequest (D112).
export const dashboardForgotSchema = z.object({
  email: z.string().trim().min(1, ar.validation.required).pipe(z.email(ar.validation.email)),
});

export const dashboardResetSchema = z.object({
  code: z
    .string()
    .trim()
    .regex(/^\d{6}$/, ar.validation.otp),
  password: z.string().min(8, ar.validation.passwordMin).max(200, ar.validation.max(200)),
});

type ForgotValues = z.infer<typeof dashboardForgotSchema>;
type ResetValues = z.infer<typeof dashboardResetSchema>;

/**
 * D112, BR-T10: a dashboard user who forgot their password. Step 1 sends a code on WhatsApp to the mobile stored on
 * the user (the same answer for every email); step 2 takes the code and the new password, then back to the login.
 */
export function DashboardForgotPasswordForm() {
  const [email, setEmail] = useState<string | null>(null);

  return (
    <Card className="w-full max-w-sm">
      <CardHeader className="border-b">
        <CardTitle>
          <h1 className="text-xl font-extrabold">{email === null ? ar.auth.forgotTitle : ar.auth.resetTitle}</h1>
        </CardTitle>
        <CardDescription>
          {email === null ? ar.auth.dashboardForgotBody : ar.auth.dashboardCodeSent(email)}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {email === null ? (
          <EmailStep onSent={setEmail} />
        ) : (
          <ResetStep email={email} onChangeEmail={() => setEmail(null)} />
        )}
      </CardContent>
      <CardFooter className="flex flex-col items-start gap-2 border-t text-sm">
        <p className="text-muted-foreground">{ar.auth.dashboardNoPhone}</p>
        <Link href={loginPaths.dashboard} className="text-primary underline-offset-4 hover:underline">
          {ar.auth.backToLogin}
        </Link>
      </CardFooter>
    </Card>
  );
}

function EmailStep({ onSent }: { onSent: (email: string) => void }) {
  const forgot = useDashboardForgotPassword();
  const form = useForm<ForgotValues>({ resolver: zodResolver(dashboardForgotSchema), defaultValues: { email: "" } });

  const submit = form.handleSubmit((values) =>
    forgot.mutate(values, {
      onSuccess: () => onSent(values.email),
      onError: (error) => {
        if (!applyServerErrors(error, ["email"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup>
        <TextField
          id="email"
          label={ar.auth.email}
          type="email"
          dir="ltr"
          autoComplete="username"
          registration={form.register("email")}
          error={errors.email}
        />
        {errors.root?.message !== undefined && <FieldError role="alert">{errors.root.message}</FieldError>}
        <Button type="submit" size="lg" className="h-11 w-full" disabled={forgot.isPending}>
          {forgot.isPending ? ar.auth.sending : ar.auth.sendCode}
        </Button>
      </FieldGroup>
    </form>
  );
}

function ResetStep({ email, onChangeEmail }: { email: string; onChangeEmail: () => void }) {
  const router = useRouter();
  const reset = useDashboardResetPassword();
  const resend = useDashboardForgotPassword();
  const form = useForm<ResetValues>({
    resolver: zodResolver(dashboardResetSchema),
    defaultValues: { code: "", password: "" },
  });

  const submit = form.handleSubmit((values) =>
    reset.mutate(
      { email, ...values },
      {
        onSuccess: () => {
          toast.success(ar.auth.resetDone);
          router.replace(loginPaths.dashboard);
        },
        onError: (error) => {
          if (!applyServerErrors(error, ["code", "password"], form.setError)) {
            form.setError("root", { message: messageFor(error) });
          }
        },
      },
    ),
  );

  const sendAgain = () =>
    resend.mutate(
      { email },
      {
        onSuccess: () => {
          form.clearErrors();
          toast.success(ar.otp.resent);
        },
        onError: (error) => form.setError("root", { message: messageFor(error) }),
      },
    );

  const { errors } = form.formState;

  return (
    <form onSubmit={submit} noValidate>
      <FieldGroup>
        <TextField
          id="code"
          label={ar.otp.code}
          dir="ltr"
          inputMode="numeric"
          autoComplete="one-time-code"
          registration={form.register("code")}
          error={errors.code}
        />
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
        <div className="flex flex-wrap justify-between gap-2">
          <Button type="button" variant="link" className="px-0" disabled={resend.isPending} onClick={sendAgain}>
            {ar.otp.resend}
          </Button>
          <Button type="button" variant="link" className="px-0" onClick={onChangeEmail}>
            {ar.auth.changeEmail}
          </Button>
        </div>
      </FieldGroup>
    </form>
  );
}
