"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { safeNext, useCustomerLogin } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { applyServerErrors } from "@/lib/forms/server-errors";

// Mirrors CustomerLoginRequest. The backend's EgyptianPhone rule is the authority; this only catches typos.
export const customerLoginSchema = z.object({
  phone: z
    .string()
    .trim()
    .min(1, ar.validation.required)
    .regex(/^(\+?20|0)?1[0125]\d{8}$/, ar.validation.phone),
  password: z.string().min(1, ar.validation.required),
});

type CustomerLoginValues = z.infer<typeof customerLoginSchema>;

/** Placeholder login for the storefront (Task 9 adds register, OTP and reset around it). */
export function CustomerLoginForm() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get("next"), "/");
  const login = useCustomerLogin();
  const form = useForm<CustomerLoginValues>({
    resolver: zodResolver(customerLoginSchema),
    defaultValues: { phone: "", password: "" },
  });

  const submit = form.handleSubmit((values) =>
    login.mutate(values, {
      onSuccess: () => router.replace(next),
      onError: (error) => {
        if (!applyServerErrors(error, ["phone", "password"], form.setError)) {
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
          <h1 className="text-xl">{ar.auth.customerLoginTitle}</h1>
        </CardTitle>
      </CardHeader>
      <CardContent>
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
            <Button type="submit" size="lg" className="h-11 w-full" disabled={login.isPending}>
              {login.isPending ? ar.auth.submitting : ar.auth.submitLogin}
            </Button>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  );
}
