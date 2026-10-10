"use client";

import { MenuIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";
import { type ReactNode, useState } from "react";

import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { useRequiredDashboardUser } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar, messageFor } from "@/lib/copy/ar";

import { DashboardNav } from "./DashboardNav";
import { NotificationsBell } from "./NotificationsBell";
import { UserMenu } from "./UserMenu";

/**
 * The frame of every dashboard page except login: the session guard, a sidebar on large screens and a
 * drawer on phones (start side, which is the right in RTL), the tenant's name and logo, the bell and the
 * user menu. Pages render inside `<main>`, which stays hidden until the session is known: the page is always
 * rendered (so the instant-navigation validation of cacheComponents sees it), but nothing shows to a guest,
 * whose own calls get a 401 and are sent to the login like the guard does.
 */
export function DashboardShell({ children }: { children: ReactNode }) {
  const session = useRequiredDashboardUser();
  const { data: tenant } = useTenant();
  const [menuOpen, setMenuOpen] = useState(false);

  if (session.isError) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(session.error)} />;
  }

  const me = session.data;

  if (!me) {
    return (
      <>
        <p className="text-muted-foreground p-6">{ar.loading}</p>
        <main hidden>{children}</main>
      </>
    );
  }

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-background/95 sticky top-0 z-40 flex h-14 items-center gap-2 border-b px-2 backdrop-blur sm:px-4">
        <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
          <SheetTrigger asChild>
            <Button variant="ghost" size="icon" className="size-11 lg:hidden" aria-label={ar.dashboard.nav.open}>
              <MenuIcon />
            </Button>
          </SheetTrigger>
          <SheetContent side="start" className="w-72 overflow-y-auto p-0">
            <SheetTitle className="flex h-14 items-center border-b px-4">{ar.dashboard.nav.label}</SheetTitle>
            <DashboardNav onNavigate={() => setMenuOpen(false)} />
          </SheetContent>
        </Sheet>
        <Link href="/dashboard" className="flex min-w-0 flex-1 items-center gap-2">
          {tenant?.logo_url && (
            <Image src={tenant.logo_url} alt="" width={32} height={32} className="size-8 rounded-md object-contain" />
          )}
          <span data-testid="tenant-name" className="text-primary truncate font-bold">
            {me.tenant.name}
          </span>
        </Link>
        <NotificationsBell />
        <UserMenu user={me.user} />
      </header>
      <div className="flex flex-1">
        <aside className="bg-muted/30 hidden w-60 shrink-0 border-e lg:block">
          <div className="sticky top-14 max-h-[calc(100dvh-3.5rem)] overflow-y-auto">
            <DashboardNav />
          </div>
        </aside>
        <main className="flex w-full min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
