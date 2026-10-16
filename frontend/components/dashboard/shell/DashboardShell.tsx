"use client";

import Image from "next/image";
import Link from "next/link";
import { type ReactNode, useState } from "react";

import { StatusPage } from "@/components/shared/StatusPage";
import { Separator } from "@/components/ui/separator";
import {
  Sidebar,
  SidebarContent,
  SidebarHeader,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
  useSidebar,
} from "@/components/ui/sidebar";
import { Skeleton } from "@/components/ui/skeleton";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { TenantSettings } from "@/lib/api/types";
import { useRequiredDashboardUser } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar, messageFor } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

import { StoreWhatsAppBanner } from "../admin/StoreWhatsAppCard";
import { DashboardNav } from "./DashboardNav";
import { NotificationsBell } from "./NotificationsBell";
import { UserMenu } from "./UserMenu";

const copy = ar.dashboard.nav;

/** The sidebar's last state, which the shadcn sidebar keeps in the `sidebar_state` cookie; open by default. */
function sidebarWasOpen(): boolean {
  return typeof document === "undefined" || !document.cookie.split("; ").includes("sidebar_state=false");
}

/**
 * The frame of every dashboard page except login: the session guard, a sidebar at the start side (the right
 * in RTL) that collapses to icons on wide screens (Ctrl+B) and is a drawer on phones, the tenant's name and
 * logo, the bell and the user menu. Pages render inside `<main>`, which stays hidden until the session is
 * known: the page is always rendered (so the instant-navigation validation of cacheComponents sees it), but
 * nothing shows to a guest, whose own calls get a 401 and are sent to the login like the guard does.
 */
export function DashboardShell({ children }: { children: ReactNode }) {
  const session = useRequiredDashboardUser();
  const [defaultOpen] = useState(sidebarWasOpen);

  if (session.isError) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(session.error)} />;
  }

  const me = session.data;

  if (!me) {
    return (
      <>
        <ShellSkeleton />
        <main hidden>{children}</main>
      </>
    );
  }

  return (
    <SidebarProvider defaultOpen={defaultOpen}>
      <Sidebar side="start" collapsible="icon" label={copy.label}>
        <SidebarHeader className="h-14 justify-center border-b">
          <DesktopBrand name={me.tenant.name} />
        </SidebarHeader>
        <SidebarContent>
          <DashboardNav />
        </SidebarContent>
        {/* A drag-edge for the mouse; keyboard and screen-reader users have the trigger in the top bar. */}
        <SidebarRail aria-hidden />
      </Sidebar>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="bg-background/95 sticky top-0 z-40 flex h-14 items-center gap-2 border-b px-2 backdrop-blur sm:px-4">
          <Tooltip>
            <TooltipTrigger asChild>
              <SidebarTrigger aria-label={copy.toggle} className="size-11 md:size-9" />
            </TooltipTrigger>
            <TooltipContent side="bottom">{copy.toggleHint}</TooltipContent>
          </Tooltip>
          <Separator orientation="vertical" className="hidden h-5! self-center md:block" />
          <MobileBrand name={me.tenant.name} />
          <NotificationsBell />
          <UserMenu user={me.user} />
        </header>
        <StoreWhatsAppBanner />
        <main className="flex w-full min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">{children}</main>
      </div>
    </SidebarProvider>
  );
}

type BrandProps = {
  name: string;
  tenant: TenantSettings | undefined;
  className: string;
  nameClassName?: string;
  /** Only one copy on the page carries the test id: the drawer copy on phones stays unmarked. */
  testId?: string;
};

function Brand({ name, tenant, className, nameClassName, testId }: BrandProps) {
  return (
    <Link href="/dashboard" className={className}>
      {tenant?.logo_url ? (
        <Image
          src={tenant.logo_url}
          alt=""
          width={32}
          height={32}
          className="size-8 shrink-0 rounded-md object-contain"
        />
      ) : (
        <span
          aria-hidden
          className="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-md text-sm font-bold"
        >
          {name.slice(0, 1)}
        </span>
      )}
      <span data-testid={testId} className={cn("text-primary truncate font-bold", nameClassName)}>
        {name}
      </span>
    </Link>
  );
}

/** The tenant at the top of the sidebar (and of the phone drawer); only the logo shows when it is collapsed. */
function DesktopBrand({ name }: { name: string }) {
  const { isMobile } = useSidebar();
  const { data: tenant } = useTenant();

  return (
    <Brand
      name={name}
      tenant={tenant}
      testId={isMobile ? undefined : "tenant-name"}
      className="flex min-w-0 items-center gap-2 px-1 group-data-[collapsible=icon]:px-0"
      nameClassName="group-data-[collapsible=icon]:hidden"
    />
  );
}

/** On phones the sidebar is a closed drawer, so the tenant sits in the top bar. Rendered once, never twice. */
function MobileBrand({ name }: { name: string }) {
  const { isMobile } = useSidebar();
  const { data: tenant } = useTenant();

  return isMobile ? (
    <Brand name={name} tenant={tenant} testId="tenant-name" className="flex min-w-0 flex-1 items-center gap-2" />
  ) : (
    <span className="flex-1" />
  );
}

/** The frame's outline while the session loads, so the page does not jump when it arrives. */
function ShellSkeleton() {
  return (
    <div className="flex min-h-svh" aria-busy="true" aria-label={ar.loading}>
      <div className="hidden w-64 shrink-0 flex-col gap-3 border-e p-3 md:flex">
        <Skeleton className="h-8 w-40" />
        {Array.from({ length: 8 }, (_, index) => (
          <Skeleton key={index} className="h-8 w-full" />
        ))}
      </div>
      <div className="flex flex-1 flex-col">
        <div className="flex h-14 items-center gap-2 border-b px-4">
          <Skeleton className="size-8" />
          <Skeleton className="h-5 w-32" />
        </div>
        <div className="flex flex-col gap-3 p-4 md:p-6">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full" />
        </div>
      </div>
    </div>
  );
}
