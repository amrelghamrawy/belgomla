"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { loginPaths } from "@/lib/api/client";
import { useDashboardSession, useLogout } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";

/** The "me" card: who is logged in, on which store. Task 10 turns this into the dashboard home. */
export function DashboardHome() {
  const router = useRouter();
  const session = useDashboardSession();
  const logout = useLogout("dashboard");

  useEffect(() => {
    if (session.data === null) {
      router.replace(`${loginPaths.dashboard}?next=${encodeURIComponent("/dashboard")}`);
    }
  }, [session.data, router]);

  if (session.isError) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(session.error)} />;
  }

  if (!session.data) {
    return <p className="text-muted-foreground p-6">{ar.loading}</p>;
  }

  const { user, tenant } = session.data;

  return (
    <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-8">
      <h1 className="text-2xl font-bold">{ar.dashboard.title}</h1>
      <Card>
        <CardHeader>
          <CardTitle>
            {ar.dashboard.signedInAs} <span data-testid="user-name">{user.name}</span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted-foreground">{ar.auth.email}</dt>
            <dd>
              <bdi>{user.email}</bdi>
            </dd>
            <dt className="text-muted-foreground">{ar.dashboard.role}</dt>
            <dd>{ar.roles[user.role] ?? user.role}</dd>
            <dt className="text-muted-foreground">{ar.dashboard.store}</dt>
            <dd data-testid="tenant-name">{tenant.name}</dd>
          </dl>
        </CardContent>
      </Card>
      <Button
        variant="outline"
        size="lg"
        className="h-11 self-start"
        disabled={logout.isPending}
        onClick={() => logout.mutate()}
      >
        {ar.auth.logout}
      </Button>
    </main>
  );
}
