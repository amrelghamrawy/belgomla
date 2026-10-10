"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { safeNext, useDashboardLogin } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";

// Mirrors DashboardLoginRequest: email required + valid, password required.
export const dashboardLoginSchema = z.object({
  email: z.string().trim().min(1, ar.validation.required).pipe(z.email(ar.validation.email)),
  password: z.string().min(1, ar.validation.required),
});

type DashboardLoginValues = z.infer<typeof dashboardLoginSchema>;

export function DashboardLoginForm() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"), "/dashboard");
  const login = useDashboardLogin();
  const form = useForm<DashboardLoginValues>({
    resolver: zodResolver(dashboardLoginSchema),
    defaultValues: { email: "", password: "" },
  });

  const submit = form.handleSubmit((values) =>
    login.mutate(values, {
      onSuccess: () => router.replace(next),
      onError: (error) => {
        if (!applyServerErrors(error, ["email", "password"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>
          <h1 className="text-xl">{ar.auth.dashboardLoginTitle}</h1>
        </CardTitle>
      </CardHeader>
      <CardContent>
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
            <Button type="submit" size="lg" className="h-11 w-full" disabled={login.isPending}>
              {login.isPending ? ar.auth.submitting : ar.auth.submitLogin}
            </Button>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  );
}
