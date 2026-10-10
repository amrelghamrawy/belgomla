"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { FieldError, FieldGroup } from "@/components/ui/field";
import { Skeleton } from "@/components/ui/skeleton";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { useAcceptInvitation, useInvitation } from "@/lib/store/auth";
import { useRouteParam } from "@/lib/store/route";

import { AuthCard } from "./AuthCard";

export const invitationSchema = z.object({
  password: z.string().min(8, ar.validation.passwordMin).max(200, ar.validation.max(200)),
});

type InvitationValues = z.infer<typeof invitationSchema>;

/** `/account/invite/[token]` (BR-CU08): an invited customer sets a password, which verifies the phone and logs in. */
export function InvitationForm() {
  const token = useRouteParam("token");
  const router = useRouter();
  const invitation = useInvitation(token);
  const accept = useAcceptInvitation(token);
  const form = useForm<InvitationValues>({ resolver: zodResolver(invitationSchema), defaultValues: { password: "" } });

  if (invitation.isError) {
    return (
      <AuthCard title={ar.auth.inviteTitle}>
        <p role="alert" className="text-destructive">
          {ar.auth.inviteInvalid}
        </p>
      </AuthCard>
    );
  }

  if (invitation.data === undefined) {
    return (
      <AuthCard title={ar.auth.inviteTitle}>
        <Skeleton className="h-32" />
      </AuthCard>
    );
  }

  const submit = form.handleSubmit((values) =>
    accept.mutate(values, {
      onSuccess: () => router.replace("/"),
      onError: (error) => {
        if (!applyServerErrors(error, ["password"], form.setError)) {
          form.setError("root", { message: messageFor(error) });
        }
      },
    }),
  );

  const { errors } = form.formState;

  return (
    <AuthCard
      title={ar.auth.inviteTitle}
      description={
        <>
          {ar.auth.inviteBody(invitation.data.name)}
          <br />
          {ar.auth.inviteFor}: <bdi>{invitation.data.phone_masked}</bdi>
          <br />
          {ar.auth.inviteExpires(formatDate(invitation.data.expires_at))}
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <FieldGroup>
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
          <Button type="submit" size="lg" className="h-11 w-full" disabled={accept.isPending}>
            {accept.isPending ? ar.saving : ar.auth.inviteSubmit}
          </Button>
        </FieldGroup>
      </form>
    </AuthCard>
  );
}
