"use client";

import Link from "next/link";

import { Button } from "@/components/ui/button";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";

/** Placeholder store home: the store's name and logo. Task 9 builds the real home page. */
export function StoreHome() {
  const { data: tenant } = useTenant();

  return (
    <main className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center gap-6 p-6 text-center">
      {tenant?.logo_url && (
        // eslint-disable-next-line @next/next/no-img-element -- tenant logos come from the backend's storage host; next/image arrives with Task 9's image config
        <img src={tenant.logo_url} alt="" className="size-24 rounded-xl object-contain" />
      )}
      <div className="flex flex-col gap-2">
        <p className="text-muted-foreground">{ar.store.welcome}</p>
        <h1 className="text-primary text-3xl font-bold" data-testid="tenant-name">
          {tenant?.name ?? ar.loading}
        </h1>
        {tenant?.description && <p className="text-muted-foreground">{tenant.description}</p>}
      </div>
      <p className="text-muted-foreground text-sm">{ar.store.comingSoon}</p>
      <Button asChild size="lg" className="h-11 w-full">
        <Link href="/account/login">{ar.store.login}</Link>
      </Button>
    </main>
  );
}
